<?php

namespace App\Jobs;

use App\Models\DereuWebhookEvent;
use App\Services\OperatorEchoJournal;
use App\Services\OperatorHandoff;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Records a message the operator sent from the WhatsApp Business app, and
 * — when it lands in a conversation the bot is currently holding — steps
 * the bot aside so the two do not talk over each other.
 */
class JournalDereuOperatorEcho implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [5, 30, 120];

    public function __construct(public DereuWebhookEvent $event) {}

    public function handle(OperatorEchoJournal $journal, OperatorHandoff $handoff): void
    {
        $event = $this->event->fresh();

        if ($event === null || $event->processed_at !== null || $event->event !== 'business_app_message_echo') {
            return;
        }

        if (! $event->belongsToCurrentCompany()) {
            Log::warning('Dereu operator echo belongs to an unknown company, skipping.', [
                'event_id' => $event->event_id,
                'company_id' => $event->company_id,
            ]);
            $event->update(['processed_at' => now()]);

            return;
        }

        $entry = $journal->record($event);

        if ($entry !== null) {
            $handoff->considerEcho($entry);
        }

        $event->update(['processed_at' => now()]);
    }
}
