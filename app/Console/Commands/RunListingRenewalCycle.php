<?php

namespace App\Console\Commands;

use App\Models\Listing;
use App\Services\ListingRenewalNotifier;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * The daily 30-day relevance cycle (docs/modules/listings-lifecycle.md):
 * polls suppliers a day before their publication expires and archives
 * publications that ran out without a confirmation, so «мёртвые души»
 * leave the search.
 */
#[Signature('listings:run-renewal-cycle')]
#[Description('Отправить 30-дневные опросы актуальности и заархивировать истёкшие объявления')]
class RunListingRenewalCycle extends Command
{
    public function handle(ListingRenewalNotifier $notifier): int
    {
        $polled = 0;

        Listing::query()->dueForRenewalPoll()->with('supplier')->get()
            ->each(function (Listing $listing) use ($notifier, &$polled): void {
                // An unsent poll (e.g. the template awaits Meta approval)
                // stays unmarked, so tomorrow's run retries it.
                if ($notifier->sendPoll($listing)) {
                    $listing->update(['renewal_requested_at' => now()]);
                    $polled++;
                }
            });

        $archived = 0;

        Listing::query()->expiredWithoutConfirmation()->get()
            ->each(function (Listing $listing) use (&$archived): void {
                $listing->archive();
                $archived++;
            });

        $this->info("Опросов отправлено: {$polled}, заархивировано: {$archived}.");

        return self::SUCCESS;
    }
}
