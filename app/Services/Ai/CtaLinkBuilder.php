<?php

namespace App\Services\Ai;

use App\Models\Listing;
use Illuminate\Support\Facades\URL;

/**
 * Builds the CTA URLs the bot hands off to when a task needs the web
 * interface — editing a draft or fixing what the AI misrecognized (see
 * docs/modules/whatsapp-integration.md, «Управление через Web»).
 *
 * Links are signed and time-limited; the supplier web portal itself is
 * Module 3 and currently a placeholder.
 */
class CtaLinkBuilder
{
    private const int LINK_TTL_DAYS = 7;

    public function draftEditUrl(Listing $listing): string
    {
        return URL::temporarySignedRoute(
            'supplier.listings.edit',
            now()->addDays(self::LINK_TTL_DAYS),
            ['listing' => $listing->getKey()],
        );
    }
}
