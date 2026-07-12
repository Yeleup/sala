<?php

namespace App\Services\Ai;

use App\Models\Contact;
use App\Models\Listing;
use Illuminate\Support\Facades\URL;

/**
 * Builds the signed URLs into the supplier web portal: the CTA links the
 * bot hands off to (editing a draft, «Мои объявления») and the portal's
 * own action endpoints (see docs/modules/whatsapp-integration.md,
 * «Веб-кабинет поставщика»).
 *
 * Every link is personal, signed and time-limited to 7 days.
 */
class CtaLinkBuilder
{
    private const int LINK_TTL_DAYS = 7;

    public function editUrl(Listing $listing): string
    {
        return $this->signed('supplier.listings.edit', ['listing' => $listing->getKey()]);
    }

    public function myListingsUrl(Contact $contact): string
    {
        return $this->signed('supplier.listings.index', ['contact' => $contact->getKey()]);
    }

    public function updateUrl(Listing $listing): string
    {
        return $this->signed('supplier.listings.update', ['listing' => $listing->getKey()]);
    }

    public function archiveUrl(Listing $listing): string
    {
        return $this->signed('supplier.listings.archive', ['listing' => $listing->getKey()]);
    }

    /**
     * @param  array<string, int|string>  $parameters
     */
    protected function signed(string $route, array $parameters): string
    {
        return URL::temporarySignedRoute($route, now()->addDays(self::LINK_TTL_DAYS), $parameters);
    }
}
