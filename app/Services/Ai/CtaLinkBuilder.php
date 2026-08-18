<?php

namespace App\Services\Ai;

use App\Enums\ListingKind;
use App\Models\Contact;
use App\Models\Listing;
use App\Models\Location;
use Illuminate\Support\Facades\URL;

/**
 * Builds the signed URLs into the web interface: the CTA links the bot
 * hands off to — the supplier portal (editing a draft, «Мои объявления»)
 * and the customer catalog — plus the pages' own action endpoints (see
 * docs/modules/whatsapp-integration.md, «Веб-кабинет поставщика» and
 * «Веб-каталог заказчика»).
 *
 * Every link is personal, signed and time-limited to 7 days.
 */
class CtaLinkBuilder
{
    private const int LINK_TTL_DAYS = 7;

    /**
     * Per-listing supplier links carry the owner in the signature: after
     * the operator hands the listing to another supplier, links issued to
     * the previous owner stop matching the current owner and die — the
     * previous owner must not edit or archive a listing that is no longer
     * theirs.
     */
    public function editUrl(Listing $listing): string
    {
        return $this->signed('supplier.listings.edit', ['listing' => $listing->getKey(), 'contact' => $listing->contact_id]);
    }

    public function myListingsUrl(Contact $contact): string
    {
        return $this->signed('supplier.listings.index', ['contact' => $contact->getKey()]);
    }

    public function updateNameUrl(Contact $contact): string
    {
        return $this->signed('supplier.listings.update-name', ['contact' => $contact->getKey()]);
    }

    public function updateUrl(Listing $listing): string
    {
        return $this->signed('supplier.listings.update', ['listing' => $listing->getKey(), 'contact' => $listing->contact_id]);
    }

    public function archiveUrl(Listing $listing): string
    {
        return $this->signed('supplier.listings.archive', ['listing' => $listing->getKey(), 'contact' => $listing->contact_id]);
    }

    /**
     * The customer catalog with the chat search prefilled. The prefill
     * params ride outside the signature on purpose: the catalog route
     * ignores everything but the path and expiry when validating (see
     * ValidateSignatureExceptQuery), so the filter form and pagination
     * keep working on the same personal link.
     */
    public function catalogUrl(Contact $contact, ?string $query = null, ?Location $location = null, ?ListingKind $kind = null): string
    {
        $url = $this->signed('customer.listings.index', ['contact' => $contact->getKey()]);

        $prefill = http_build_query(array_filter([
            'q' => $query,
            'location_id' => $location?->getKey(),
            // Every branch carries its kind, rental included: the chat search
            // filters hard by kind, so a catalog that quietly widened the
            // results would contradict the very outcome it was handed off from.
            'kind' => $kind?->value,
        ]));

        return $prefill === '' ? $url : $url.'&'.$prefill;
    }

    /**
     * The catalog's listing page: all photos and the full description of
     * one listing. Like the catalog itself, the signature covers only the
     * path and expiry — the appended filter state for the «back» link
     * never breaks the link.
     */
    public function listingUrl(Contact $contact, Listing $listing): string
    {
        return $this->signed('customer.listings.show', [
            'contact' => $contact->getKey(),
            'listing' => $listing->getKey(),
        ]);
    }

    public function selectUrl(Contact $contact, Listing $listing): string
    {
        return $this->signed('customer.listings.select', [
            'contact' => $contact->getKey(),
            'listing' => $listing->getKey(),
        ]);
    }

    /**
     * @param  array<string, int|string>  $parameters
     */
    protected function signed(string $route, array $parameters): string
    {
        return URL::temporarySignedRoute($route, now()->addDays(self::LINK_TTL_DAYS), $parameters);
    }
}
