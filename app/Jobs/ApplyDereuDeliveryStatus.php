<?php

namespace App\Jobs;

use App\Enums\ChannelMessageStatus;
use App\Models\ChannelMessage;
use App\Models\DereuWebhookEvent;
use App\Models\WhatsappTemplate;
use App\Services\DereuMessenger;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

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

        $entry = ChannelMessage::query()
            ->where('dereu_message_id', $dereuMessageId)
            ->first();

        $entry?->applyDeliveryStatus(
            $status,
            wamid: $event->payload['wamid'] ?? null,
            failureReason: $event->payload['reason'] ?? null,
        );

        // The async rejection is the known failure profile of the channel
        // (Meta declines after the send job is already DONE — the bot «goes
        // silent»): the journal row alone is passive, an error-level log
        // entry is what monitoring actually watches.
        if ($status === ChannelMessageStatus::Failed) {
            Log::error('WhatsApp message failed to deliver (async rejection).', [
                'dereu_message_id' => $dereuMessageId,
                'contact_id' => $entry?->contact_id,
                'reason' => $event->payload['reason'] ?? null,
            ]);

            $this->resendThroughTemplateFallback($entry, (string) ($event->payload['reason'] ?? ''));
        }

        $event->update(['processed_at' => now()]);
    }

    /**
     * The plan B of a session notification: the window closed between our
     * check and Meta's processing, but the notification carries an approved
     * paid template that still delivers. One shot only — the fallback is
     * claimed atomically before sending, so a redelivered failure event
     * cannot pay twice, and the template send itself never carries a
     * fallback, so a failed re-send cannot loop.
     */
    private function resendThroughTemplateFallback(?ChannelMessage $entry, string $reason): void
    {
        if ($entry === null || $entry->template_fallback === null || ! $this->isWindowRejection($reason)) {
            return;
        }

        $claimed = ChannelMessage::query()
            ->whereKey($entry->id)
            ->whereNotNull('template_fallback')
            ->update(['template_fallback' => null]) === 1;

        if (! $claimed) {
            return;
        }

        $fallback = $entry->template_fallback;
        $template = WhatsappTemplate::find($fallback['whatsapp_template_id'] ?? null);

        if ($template === null || ! $template->isApproved()) {
            Log::warning('The fallback template is missing or unapproved — the notification stays undelivered.', [
                'channel_message_id' => $entry->id,
                'whatsapp_template_id' => $fallback['whatsapp_template_id'] ?? null,
            ]);

            return;
        }

        try {
            app(DereuMessenger::class)->sendTemplate(
                $entry->contact,
                $template,
                array_map(strval(...), (array) ($fallback['body_parameters'] ?? [])),
                array_map(strval(...), (array) ($fallback['button_payloads'] ?? [])),
            );

            Log::info('Session notification re-sent as a paid template after an async window rejection.', [
                'channel_message_id' => $entry->id,
                'whatsapp_template_id' => $template->id,
            ]);
        } catch (\Throwable $e) {
            Log::warning('Failed to re-send the notification as a template.', [
                'channel_message_id' => $entry->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Only the window rejection gets the template retry: any other cause
     * (invalid payload, blocked recipient) could fail the template the
     * same way, and the journal keeps the fallback for the operator.
     */
    private function isWindowRejection(string $reason): bool
    {
        $reason = mb_strtolower($reason);

        return str_contains($reason, '131047')
            || str_contains($reason, '24 hour')
            || str_contains($reason, 're-engagement');
    }
}
