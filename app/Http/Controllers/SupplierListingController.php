<?php

namespace App\Http\Controllers;

use App\Enums\ListingMediaType;
use App\Enums\ListingStatus;
use App\Http\Requests\UpdateSupplierListingRequest;
use App\Http\Requests\UpdateSupplierNameRequest;
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
 * with re-submission to moderation, archiving a published one, and
 * changing the supplier's display name.
 * See docs/modules/whatsapp-integration.md, «Веб-кабинет поставщика».
 */
class SupplierListingController extends Controller
{
    public function __construct(private readonly CtaLinkBuilder $links) {}

    public function index(Contact $contact): View
    {
        $listings = $contact->listings()->with(['category', 'location'])->latest()->get();

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
        ]);
    }

    public function edit(Listing $listing): View
    {
        $listing->load(['photos', 'audioMessages']);

        return view('supplier.listing-edit', [
            'listing' => $listing,
            'categories' => Category::query()->orderBy('name')->get(),
            'editable' => $this->isEditable($listing),
            'indexUrl' => $this->links->myListingsUrl($listing->supplier),
            'updateUrl' => $this->links->updateUrl($listing),
            'archiveUrl' => $listing->status === ListingStatus::Published ? $this->links->archiveUrl($listing) : null,
        ]);
    }

    public function update(UpdateSupplierListingRequest $request, Listing $listing): RedirectResponse
    {
        abort_unless($this->isEditable($listing), 403);

        $listing->fill($request->safe()->except(['photos', 'remove_photos']))->save();
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
        abort_unless($listing->status === ListingStatus::Published, 403);

        $listing->archive();

        return redirect()
            ->to($this->links->myListingsUrl($listing->supplier))
            ->with('status', 'Объявление снято с публикации.');
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
}
