<?php

namespace App\Services;

use App\Enums\BotScenarioTrigger;
use App\Enums\ListingStatus;
use App\Models\ChannelMessage;
use App\Models\Listing;
use App\Models\ListingRenewalBatch;
use App\Models\ScenarioRun;
use App\Services\Bot\NotificationReplyHandler;
use App\Services\Bot\ScenarioRunReplyHandler;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Undoes the «спросили» mark of a 30-day relevance poll whose message Meta
 * rejected after Dereu had already accepted it (message_failed): a question
 * that never reached the supplier must not count as asked, or the next
 * daily cycle would silently archive the listing instead of spending the
 * grace day on re-polling it (see Listing::dueForRenewalPoll).
 *
 * The failed message is tied back to its listings through the button ids
 * it carried — the same ids the supplier's answer would have carried: the
 * renewal_* buttons of the built-in notifier and the flow:{token} buttons
 * of a scenario run whose subject is the listing or the batch.
 */
class ListingRenewalPollFailureHandler
{
    public function handle(ChannelMessage $failed): void
    {
        $unmarked = $this->polledListings($failed)
            ->unique('id')
            ->filter(fn (Listing $listing): bool => $this->pollBelongsToCurrentCycle($listing, $failed))
            ->each(fn (Listing $listing) => $listing->update(['renewal_requested_at' => null]));

        if ($unmarked->isNotEmpty()) {
            Log::warning('The renewal poll never reached the supplier — its listings will be re-polled.', [
                'channel_message_id' => $failed->id,
                'listing_ids' => $unmarked->pluck('id')->all(),
            ]);
        }
    }

    /**
     * Every listing the failed message was polling about.
     *
     * @return Collection<int, Listing>
     */
    protected function polledListings(ChannelMessage $failed): Collection
    {
        $buttonIds = $failed->buttonIds();

        $listings = Listing::query()
            ->findMany($buttonIds->map(NotificationReplyHandler::renewalButtonListingId(...))->filter());

        $batches = ListingRenewalBatch::query()
            ->with('listings')
            ->findMany($buttonIds->map(NotificationReplyHandler::renewalButtonBatchId(...))->filter());

        return collect($listings)
            ->merge($batches->flatMap->listings)
            ->merge($this->scenarioRunListings($buttonIds));
    }

    /**
     * The listings a scenario-run poll was about, resolved through the
     * run's subject. Only runs of the renewal triggers count: a failed
     * message of any other scenario says nothing about the poll.
     *
     * @param  Collection<int, string>  $buttonIds
     * @return Collection<int, Listing>
     */
    protected function scenarioRunListings(Collection $buttonIds): Collection
    {
        $tokens = $buttonIds->map(ScenarioRunReplyHandler::runToken(...))->filter();

        if ($tokens->isEmpty()) {
            return collect();
        }

        return ScenarioRun::query()
            ->whereIn('token', $tokens->all())
            ->with(['scenario', 'subject'])
            ->get()
            ->filter(fn (ScenarioRun $run): bool => in_array(
                $run->scenario?->trigger,
                [BotScenarioTrigger::ListingExpiring, BotScenarioTrigger::ListingsExpiringBatch],
                true,
            ))
            ->flatMap(fn (ScenarioRun $run): Collection => match (true) {
                $run->subject instanceof Listing => collect([$run->subject]),
                $run->subject instanceof ListingRenewalBatch => collect($run->subject->listings),
                default => collect(),
            });
    }

    /**
     * Whether the failed poll is the one the listing's current mark stands
     * for. A poll is only ever sent in the listing's last day or the grace
     * day after it, so a message older than that belongs to a superseded
     * 30-day cycle — a stale redelivered failure must not unmark a fresh
     * poll.
     */
    protected function pollBelongsToCurrentCycle(Listing $listing, ChannelMessage $failed): bool
    {
        return $listing->status === ListingStatus::Published
            && $listing->renewal_requested_at !== null
            && $listing->expires_at !== null
            && $failed->created_at !== null
            && $failed->created_at->greaterThanOrEqualTo($listing->expires_at->copy()->subDay());
    }
}
