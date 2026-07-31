<?php

namespace App\Models;

use App\Jobs\ApplyDereuDeliveryStatus;
use App\Jobs\ApplyDereuTemplateStatus;
use App\Jobs\ApplyDereuWabaDisconnect;
use App\Jobs\ProcessDereuWebhookEvent;
use Database\Factories\DereuWebhookEventFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A webhook event forwarded by Dereu (inbound messages and delivery statuses).
 *
 * company_id holds Dereu's internal dereu_company_id, not our external_id.
 */
#[Fillable(['event', 'event_id', 'dedupe_key', 'company_id', 'phone_number_id', 'wamid', 'payload', 'processed_at'])]
class DereuWebhookEvent extends Model
{
    /** @use HasFactory<DereuWebhookEventFactory> */
    use HasFactory;

    /**
     * @return array{payload: 'array', processed_at: 'datetime'}
     */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'processed_at' => 'datetime',
        ];
    }

    /**
     * The queue job that processes this event type; null for event types
     * stored for observability only. The single source of truth shared by
     * the webhook controller (first dispatch) and the redispatch sweeper —
     * so an event the controller queues is exactly an event the sweeper
     * can re-queue.
     *
     * @return class-string|null
     */
    public function jobClass(): ?string
    {
        return match (true) {
            $this->event === 'message_received' => ProcessDereuWebhookEvent::class,
            $this->event === 'template_status_update' => ApplyDereuTemplateStatus::class,
            in_array($this->event, ['message_sent', 'message_delivered', 'message_read', 'message_failed'], true) => ApplyDereuDeliveryStatus::class,
            $this->event === 'waba_disconnected' => ApplyDereuWabaDisconnect::class,
            default => null,
        };
    }
}
