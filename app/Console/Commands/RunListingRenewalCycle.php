<?php

namespace App\Console\Commands;

use App\Enums\BotScenarioTrigger;
use App\Models\BotScenario;
use App\Models\Listing;
use App\Models\ListingRenewalBatch;
use App\Services\Bot\ScenarioRunner;
use App\Services\ListingRenewalNotifier;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * The daily 30-day relevance cycle (docs/modules/listings-lifecycle.md):
 * polls suppliers a day before their publication expires and archives
 * publications that ran out without a confirmation, so «мёртвые души»
 * leave the search.
 *
 * The poll is per supplier, not per listing: everything of theirs that
 * comes due in the same run is asked about in one message, so a fleet of
 * twelve listings costs one paid template instead of twelve. A supplier
 * with a single expiring listing keeps the per-listing question, which
 * can name it.
 *
 * A batch that cannot go out (the batch template is not approved by Meta
 * yet, a transport failure) degrades to the per-listing questions right
 * here, in the same run. There is no second chance: a publication is
 * pollable only on its last day, and the next run archives it. Silence
 * would cost the supplier his whole search presence — twelve paid
 * templates are the cheaper accident.
 *
 * The poll itself is orchestrated by the published «Истекает объявление»
 * / «Истекает несколько объявлений» scenarios (an isolated run per poll);
 * while none is published, the legacy hardcoded notifier keeps polling.
 * The auto-archive of expired publications is a hard business rule and
 * stays here either way.
 */
#[Signature('listings:run-renewal-cycle')]
#[Description('Отправить 30-дневные опросы актуальности и заархивировать истёкшие объявления')]
class RunListingRenewalCycle extends Command
{
    public function handle(ScenarioRunner $runner, ListingRenewalNotifier $notifier): int
    {
        $polled = 0;

        Listing::query()->dueForRenewalPoll()->with('supplier')->get()
            ->groupBy('contact_id')
            ->each(function (Collection $listings) use ($runner, $notifier, &$polled): void {
                /** @var Collection<int, Listing> $listings */
                $polled += $this->pollSupplier($listings, $runner, $notifier);
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

    /**
     * One supplier's due publications. Returns how many of them were
     * actually asked about — an unsent question leaves its listing
     * unmarked, so nothing pretends the supplier was consulted.
     *
     * @param  Collection<int, Listing>  $listings
     */
    protected function pollSupplier(Collection $listings, ScenarioRunner $runner, ListingRenewalNotifier $notifier): int
    {
        if ($listings->count() > 1 && $this->pollBatch($listings, $runner, $notifier)) {
            $listings->each($this->markPolled(...));

            return $listings->count();
        }

        $polled = $listings->filter(fn (Listing $listing): bool => $this->pollSingle($listing, $runner, $notifier));
        $polled->each($this->markPolled(...));

        return $polled->count();
    }

    protected function markPolled(Listing $listing): void
    {
        $listing->update(['renewal_requested_at' => now()]);
    }

    protected function pollSingle(Listing $listing, ScenarioRunner $runner, ListingRenewalNotifier $notifier): bool
    {
        $scenario = BotScenario::publishedForTrigger(BotScenarioTrigger::ListingExpiring);

        return $scenario !== null
            ? $runner->launch($scenario, $listing->supplier, $listing) !== null
            : $notifier->sendPoll($listing);
    }

    /**
     * The set is recorded before the send: the answer may arrive days
     * later, and it must apply to exactly the listings the supplier was
     * asked about — never to a publication that appeared in the meantime.
     * A failed send takes the batch back down, so the per-listing
     * fallback does not leave a batch nobody was ever asked about.
     *
     * @param  Collection<int, Listing>  $listings
     */
    protected function pollBatch(Collection $listings, ScenarioRunner $runner, ListingRenewalNotifier $notifier): bool
    {
        $supplier = $listings->first()->supplier;
        $batch = ListingRenewalBatch::query()->create(['contact_id' => $supplier->getKey()]);
        $batch->listings()->attach($listings->pluck('id')->all());
        $batch->setRelation('supplier', $supplier);

        $scenario = BotScenario::publishedForTrigger(BotScenarioTrigger::ListingsExpiringBatch);

        $sent = $scenario !== null
            ? $runner->launch($scenario, $supplier, $batch) !== null
            : $notifier->sendBatchPoll($batch);

        if (! $sent) {
            $batch->listings()->detach();
            $batch->delete();
        }

        return $sent;
    }
}
