<?php

namespace App\Jobs;

use App\Enums\ChannelMessageStatus;
use App\Models\ChannelMessage;
use App\Models\DereuWebhookEvent;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Applies a Dereu delivery status event (message_sent / delivered / read /
 * failed) to the outbound journal entry. Correlation — by message_id: the
 * uuid Dereu returned on POST /messages/send; the wamid arrives with the
 * first sent-status and is stored for cross-referencing with Meta.
 */
class ApplyDereuDeliveryStatus implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [5, 30, 120];

    public function __construct(public DereuWebhookEvent $event) {}

    public function handle(): void
    {
        $event = $this->event->fresh();

        if ($event === null || $event->processed_at !== null) {
            return;
        }

        $status = ChannelMessageStatus::tryFrom((string) str($event->event)->after('message_'));
        $dereuMessageId = $event->payload['message_id'] ?? null;

        if ($status === null || blank($dereuMessageId)) {
            $event->update(['processed_at' => now()]);

            return;
        }

        ChannelMessage::query()
            ->where('dereu_message_id', $dereuMessageId)
            ->first()
            ?->applyDeliveryStatus(
                $status,
                wamid: $event->payload['wamid'] ?? null,
                failureReason: $event->payload['reason'] ?? null,
            );

        $event->update(['processed_at' => now()]);
    }
}
