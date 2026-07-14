<?php

namespace App\Console\Commands;

use App\Enums\BotScenarioTrigger;
use App\Models\BotScenario;
use App\Models\Listing;
use App\Services\Bot\ScenarioRunner;
use App\Services\ListingRenewalNotifier;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * The daily 30-day relevance cycle (docs/modules/listings-lifecycle.md):
 * polls suppliers a day before their publication expires and archives
 * publications that ran out without a confirmation, so «мёртвые души»
 * leave the search.
 *
 * The poll itself is orchestrated by the published «Истекает объявление»
 * scenario (an isolated run per listing); while none is published, the
 * legacy hardcoded notifier keeps polling. The auto-archive of expired
 * publications is a hard business rule and stays here either way.
 */
#[Signature('listings:run-renewal-cycle')]
#[Description('Отправить 30-дневные опросы актуальности и заархивировать истёкшие объявления')]
class RunListingRenewalCycle extends Command
{
    public function handle(ScenarioRunner $runner, ListingRenewalNotifier $notifier): int
    {
        $scenario = BotScenario::publishedForTrigger(BotScenarioTrigger::ListingExpiring);
        $polled = 0;

        Listing::query()->dueForRenewalPoll()->with('supplier')->get()
            ->each(function (Listing $listing) use ($scenario, $runner, $notifier, &$polled): void {
                $sent = $scenario !== null
                    ? $runner->launch($scenario, $listing->supplier, $listing) !== null
                    : $notifier->sendPoll($listing);

                // An unsent poll (e.g. the template awaits Meta approval)
                // stays unmarked, so tomorrow's run retries it.
                if ($sent) {
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
