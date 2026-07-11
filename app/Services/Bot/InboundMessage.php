<?php

namespace App\Services\Bot;

use App\Models\DereuWebhookEvent;

/**
 * A normalized inbound WhatsApp message as the bot engine sees it.
 *
 * replyId is the machine id of a pressed button / picked list row; text is
 * the free-text body (for interactive replies — the human title of the
 * option, so title matching works either way). Media-only messages have
 * neither and count as unrecognized input for interactive blocks.
 */
class InboundMessage
{
    public function __construct(
        public readonly ?string $text = null,
        public readonly ?string $replyId = null,
    ) {}

    public static function fromWebhookEvent(DereuWebhookEvent $event): self
    {
        $type = (string) ($event->payload['type'] ?? '');
        $payload = (array) ($event->payload['payload'] ?? []);

        return match ($type) {
            'text' => new self(text: $payload['body'] ?? null),
            'interactive' => self::fromInteractiveReply($payload),
            // Template quick replies arrive as type "button".
            'button' => new self(text: $payload['text'] ?? null, replyId: $payload['payload'] ?? null),
            default => new self(),
        };
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private static function fromInteractiveReply(array $payload): self
    {
        $reply = $payload['button_reply'] ?? $payload['list_reply'] ?? null;

        if (! is_array($reply)) {
            return new self();
        }

        return new self(text: $reply['title'] ?? null, replyId: $reply['id'] ?? null);
    }
}
