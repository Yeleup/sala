<?php

namespace App\Http\Controllers;

use App\Enums\LicenceType;
use App\Enums\ListingKind;
use App\Enums\ListingMediaType;
use App\Enums\ListingStatus;
use App\Enums\RepairPlace;
use App\Http\Requests\UpdateSupplierListingRequest;
use App\Http\Requests\UpdateSupplierNameRequest;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Contact;
use App\Models\Listing;
use App\Models\ListingMedia;
use App\Services\Ai\CtaLinkBuilder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\UploadedFile;
use Illuminate\View\View;

/**
 * The supplier web portal reached via signed CTA links from WhatsApp
 * (Module 3): «Мои объявления», editing a draft or a rejected listing
 * with re-submission to moderation, archiving a published one, renewing
 * publications (one by one or all at once), returning an archived
 * listing to the search, and changing the supplier's display name.
 * See docs/modules/whatsapp-integration.md, «Веб-кабинет поставщика».
 */
class SupplierListingController extends Controller
{
    public function __construct(private readonly CtaLinkBuilder $links) {}

    public function index(Contact $contact): View
    {
        $listings = $contact->listings()->with(['category', 'brand', 'location', 'machineCategories'])->latest()->get();

        return view('supplier.listings-index', [
            'contact' => $contact,
            'updateNameUrl' => $this->links->updateNameUrl($contact),
            'listings' => $listings,
            'editUrls' => $listings
                ->filter(fn (Listing $listing): bool => $this->isEditable($listing))
                ->mapWithKeys(fn (Listing $listing): array => [$listing->id => $this->links->editUrl($listing)]),
            'archiveUrls' => $listings
                ->where('status', ListingStatus::Published)
                ->mapWithKeys(fn (Listing $listing): array => [$listing->id => $this->links->archiveUrl($listing)]),
            'renewUrls' => $listings
                ->where('status', ListingStatus::Published)
                ->mapWithKeys(fn (Listing $listing): array => [$listing->id => $this->links->renewUrl($listing)]),
            'restoreUrls' => $listings
                ->where('status', ListingStatus::Archived)
                ->mapWithKeys(fn (Listing $listing): array => [$listing->id => $this->links->restoreUrl($listing)]),
            // «Продлить все» возвращает публикации к общей дате — иначе
            // они разъезжаются и опрос актуальности снова дробится.
            'renewAllUrl' => $listings->contains(fn (Listing $listing): bool => $listing->status === ListingStatus::Published)
                ? $this->links->renewAllUrl($contact)
                : null,
        ]);
    }

    public function edit(Listing $listing): View
    {
        $this->assertLinkIssuedToCurrentOwner($listing);

        $listing->load(['photos', 'audioMessages', 'machineCategories']);

        return view('supplier.listing-edit', [
            'listing' => $listing,
            'categories' => Category::query()->orderBy('name')->get(),
            'brands' => Brand::query()->orderBy('name')->get(),
            'repairPlaces' => RepairPlace::cases(),
            'licenceTypes' => LicenceType::cases(),
            'machineCategoryIds' => $listing->machineCategories->pluck('id')->all(),
            'hasDocument' => $listing->documents()->exists(),
            'editable' => $this->isEditable($listing),
            'indexUrl' => $this->links->myListingsUrl($listing->supplier),
            'updateUrl' => $this->links->updateUrl($listing),
            'archiveUrl' => $listing->status === ListingStatus::Published ? $this->links->archiveUrl($listing) : null,
            'renewUrl' => $listing->status === ListingStatus::Published ? $this->links->renewUrl($listing) : null,
            'restoreUrl' => $listing->status === ListingStatus::Archived ? $this->links->restoreUrl($listing) : null,
        ]);
    }

    public function update(UpdateSupplierListingRequest $request, Listing $listing): RedirectResponse
    {
        $this->assertLinkIssuedToCurrentOwner($listing);
        abort_unless($this->isEditable($listing), 403);

        $listing->fill($request->safe()->except(['photos', 'remove_photos', 'document', 'machine_categories']));

        if ($listing->kind === ListingKind::Driver) {
            // No GenerateListingEmbedding dispatch after the sync: the
            // supplier only ever edits drafts and rejected listings, and
            // the vector is (re)built when moderation publishes them.
            $listing->machineCategories()->sync($request->validated('machine_categories', []));
            $this->applyDocumentReplacement($request, $listing);
        }

        $listing->save();
        $this->applyPhotoChanges($request, $listing);
        $listing->submitForModeration();

        return redirect()
            ->to($this->links->myListingsUrl($listing->supplier))
            ->with('status', 'Объявление отправлено на проверку модератору.');
    }

    /**
     * The supplier's display name lives on the contact, so changing it here
     * renames the supplier on all of their listings at once; clearing the
     * field reverts to the WhatsApp profile name (Contact::displayName()).
     */
    public function updateName(UpdateSupplierNameRequest $request, Contact $contact): RedirectResponse
    {
        $contact->update(['display_name' => $request->validated('display_name')]);

        return redirect()
            ->to($this->links->myListingsUrl($contact))
            ->with('status', $contact->display_name === null
                ? 'Имя сброшено — используется имя из WhatsApp.'
                : 'Имя сохранено.');
    }

    public function archive(Listing $listing): RedirectResponse
    {
        $this->assertLinkIssuedToCurrentOwner($listing);
        abort_unless($listing->status === ListingStatus::Published, 403);

        $listing->archive();

        return redirect()
            ->to($this->links->myListingsUrl($listing->supplier))
            ->with('status', 'Объявление снято с публикации.');
    }

    /**
     * «Продлить»: публикация живёт ещё 30 дней с этой минуты. Отметка
     * отправленного опроса сбрасывается — следующий цикл спросит заново.
     */
    public function renew(Listing $listing): RedirectResponse
    {
        $this->assertLinkIssuedToCurrentOwner($listing);
        abort_unless($listing->status === ListingStatus::Published, 403);

        $listing->renew();

        return redirect()
            ->to($this->links->myListingsUrl($listing->supplier))
            ->with('status', sprintf('Продлили: объявление будет показываться ещё %d дней.', Listing::LIFETIME_DAYS));
    }

    /**
     * «Продлить все»: разом по всем публикациям поставщика. Именно она
     * сводит сроки к одной дате, а значит — и опрос актуальности к
     * одному сообщению вместо нескольких.
     */
    public function renewAll(Contact $contact): RedirectResponse
    {
        $listings = $contact->listings()->where('status', ListingStatus::Published)->get();

        $listings->each(fn (Listing $listing) => $listing->renew());

        return redirect()
            ->to($this->links->myListingsUrl($contact))
            ->with('status', $listings->isEmpty()
                ? 'Продлевать нечего — опубликованных объявлений нет.'
                : sprintf('Продлили все объявления — они будут показываться ещё %d дней.', Listing::LIFETIME_DAYS));
    }

    /**
     * «Вернуть в поиск»: объявление уже проходило модерацию и в архиве
     * не менялось, поэтому возвращается прямо в поиск — в том же виде,
     * в каком уже было там до архива.
     */
    public function restore(Listing $listing): RedirectResponse
    {
        $this->assertLinkIssuedToCurrentOwner($listing);
        abort_unless($listing->status === ListingStatus::Archived, 403);

        $listing->restoreFromArchive();

        return redirect()
            ->to($this->links->myListingsUrl($listing->supplier))
            ->with('status', sprintf(
                'Вернули объявление в поиск — оно будет показываться %d дней.',
                Listing::LIFETIME_DAYS,
            ));
    }

    /**
     * A newly uploaded licence photo replaces the stored one — the old
     * file goes away via the ListingMedia deleted hook — and voids the
     * operator's verification mark: it referred to the old shot. The new
     * document lands on the non-public disk, same as in the chat flow.
     */
    private function applyDocumentReplacement(UpdateSupplierListingRequest $request, Listing $listing): void
    {
        if (! $request->hasFile('document')) {
            return;
        }

        $listing->documents()->get()->each(fn (ListingMedia $document) => $document->delete());

        $listing->documents()->create([
            'type' => ListingMediaType::Document,
            'disk' => 'local',
            'path' => $request->file('document')->store("listings/{$listing->id}/documents", 'local'),
        ]);

        $listing->fill(['document_verified_at' => null, 'document_verified_by' => null]);
    }

    /**
     * Photos checked for removal go away with their files (scoping to the
     * listing's own photos guards against foreign ids); new uploads land
     * on the same disk and path layout the bot uses.
     */
    private function applyPhotoChanges(UpdateSupplierListingRequest $request, Listing $listing): void
    {
        $listing->photos()
            ->whereIn('id', $request->input('remove_photos', []))
            ->get()
            ->each(fn (ListingMedia $photo) => $photo->delete());

        collect($request->file('photos', []))->each(fn (UploadedFile $file) => $listing->photos()->create([
            'type' => ListingMediaType::Photo,
            'path' => $file->store("listings/{$listing->id}/photos", 'public'),
        ]));
    }

    /**
     * Only drafts and rejected listings are editable by the supplier;
     * saving either sends it (back) to moderation.
     */
    private function isEditable(Listing $listing): bool
    {
        return in_array($listing->status, [ListingStatus::Draft, ListingStatus::Rejected], true);
    }

    /**
     * The per-listing link is signed together with the owner it was
     * issued to (CtaLinkBuilder), so the signature itself proves the
     * pair. A mismatch with the listing's current owner means the
     * listing changed hands after the link went out — the previous
     * owner's links must die with the ownership. Strictly the query
     * string: only it is covered by the signature, a `contact` field in
     * the POST body would be attacker-controlled.
     */
    private function assertLinkIssuedToCurrentOwner(Listing $listing): void
    {
        abort_unless((int) request()->query('contact') === $listing->contact_id, 403);
    }
}
