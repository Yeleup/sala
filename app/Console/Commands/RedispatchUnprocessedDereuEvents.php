<?php

namespace App\Console\Commands;

use App\Models\DereuWebhookEvent;
use Illuminate\Console\Command;

/**
 * Re-queues stored webhook events whose job never ran: the dispatch after
 * createOrFirst failed (queue hiccup), or a worker died hard mid-flight.
 * Dereu's own redelivery cannot help here — it is deduplicated against the
 * already-stored row and never dispatches again, so without this sweep the
 * event stays unprocessed forever and the bot silently ignores the message.
 *
 * Safe to re-dispatch generously: every consumer job is idempotent by
 * processed_at, and inbound messages are additionally serialized per
 * contact, so a duplicate dispatch collapses into a no-op.
 */
class RedispatchUnprocessedDereuEvents extends Command
{
    protected $signature = 'dereu:redispatch-unprocessed-events';

    protected $description = 'Re-queue stored Dereu webhook events whose processing job never completed';

    /**
     * Younger events may still be waiting for the per-contact lock of a
     * live job (retryUntil = 10 minutes) — re-queueing those would only
     * add noise.
     */
    private const int MIN_AGE_MINUTES = 15;

    /**
     * Beyond the 24-hour WhatsApp session window a late reply confuses the
     * dialog more than it saves it, and the reply could not be delivered
     * as a session message anyway.
     */
    private const int MAX_AGE_HOURS = 24;

    public function handle(): int
    {
        $events = DereuWebhookEvent::query()
            ->whereNull('processed_at')
            ->whereBetween('created_at', [
                now()->subHours(self::MAX_AGE_HOURS),
                now()->subMinutes(self::MIN_AGE_MINUTES),
            ])
            ->orderBy('id')
            ->get();

        $redispatched = 0;

        foreach ($events as $event) {
            $job = $event->jobClass();

            if ($job === null) {
                continue;
            }

            $job::dispatch($event);
            $redispatched++;
        }

        $this->info("Redispatched {$redispatched} unprocessed Dereu webhook events.");

        return self::SUCCESS;
    }
}
