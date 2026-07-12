<?php

namespace App\Http\Controllers;

use App\Enums\ListingStatus;
use App\Http\Requests\UpdateSupplierListingRequest;
use App\Models\Contact;
use App\Models\Listing;
use App\Services\Ai\CtaLinkBuilder;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * The supplier web portal reached via signed CTA links from WhatsApp
 * (Module 3): «Мои объявления», editing a draft or a rejected listing
 * with re-submission to moderation, and archiving a published one.
 * See docs/modules/whatsapp-integration.md, «Веб-кабинет поставщика».
 */
class SupplierListingController extends Controller
{
    public function __construct(private readonly CtaLinkBuilder $links) {}

    public function index(Contact $contact): View
    {
        $listings = $contact->listings()->latest()->get();

        return view('supplier.listings-index', [
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
            'editable' => $this->isEditable($listing),
            'indexUrl' => $this->links->myListingsUrl($listing->supplier),
            'updateUrl' => $this->links->updateUrl($listing),
            'archiveUrl' => $listing->status === ListingStatus::Published ? $this->links->archiveUrl($listing) : null,
        ]);
    }

    public function update(UpdateSupplierListingRequest $request, Listing $listing): RedirectResponse
    {
        abort_unless($this->isEditable($listing), 403);

        $listing->fill($request->validated())->save();
        $listing->submitForModeration();

        return redirect()
            ->to($this->links->myListingsUrl($listing->supplier))
            ->with('status', 'Объявление отправлено на проверку модератору.');
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
     * Only drafts and rejected listings are editable by the supplier;
     * saving either sends it (back) to moderation.
     */
    private function isEditable(Listing $listing): bool
    {
        return in_array($listing->status, [ListingStatus::Draft, ListingStatus::Rejected], true);
    }
}
