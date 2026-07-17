<?php

namespace App\Services\Bot;

use App\Enums\ListingMediaType;
use App\Models\DereuWebhookEvent;

/**
 * A normalized inbound WhatsApp message as the bot engine sees it.
 *
 * replyId is the machine id of a pressed button / picked list row; text is
 * the free-text body (for interactive replies — the human title of the
 * option, so title matching works either way; for media — the caption).
 * mediaId/mediaType are set for photo and voice messages so the AI
 * assistant can download and process them.
 *
 * voiceContents/transcription are filled for voice messages by the AI
 * entry point (see ScenarioAiAssistant), which downloads and transcribes
 * the audio before any task handler runs; both stay null when the voice
 * could not be downloaded or transcribed.
 *
 * unrecognizedPress is set when a button/list press could not be resolved:
 * Meta fails to deliver button_reply content for some WhatsApp Web devices
 * migrated to LID identifiers, forwarding an error object instead (or,
 * previously, an empty payload). Which button was pressed is unrecoverable;
 * text and replyId stay empty.
 */
class InboundMessage
{
    public function __construct(
        public readonly ?string $text = null,
        public readonly ?string $replyId = null,
        public readonly ?ListingMediaType $mediaType = null,
        public readonly ?string $mediaId = null,
        public readonly bool $unrecognizedPress = false,
        public readonly ?string $voiceContents = null,
        public readonly ?string $transcription = null,
    ) {}

    public function isVoice(): bool
    {
        return $this->mediaType === ListingMediaType::Audio && $this->hasMedia();
    }

    public function withVoice(string $contents, string $transcription): self
    {
        return new self(
            text: $this->text,
            replyId: $this->replyId,
            mediaType: $this->mediaType,
            mediaId: $this->mediaId,
            unrecognizedPress: $this->unrecognizedPress,
            voiceContents: $contents,
            transcription: $transcription,
        );
    }

    public static function fromWebhookEvent(DereuWebhookEvent $event): self
    {
        $type = (string) ($event->payload['type'] ?? '');
        $payload = (array) ($event->payload['payload'] ?? []);

        return match ($type) {
            'text' => new self(text: $payload['body'] ?? null),
            'interactive' => self::fromInteractiveReply($payload),
            // Template quick replies arrive as type "button".
            'button' => new self(text: $payload['text'] ?? null, replyId: $payload['payload'] ?? null),
            'image' => self::fromMedia($payload, ListingMediaType::Photo),
            'audio' => self::fromMedia($payload, ListingMediaType::Audio),
            default => new self(),
        };
    }

    public function hasMedia(): bool
    {
        return $this->mediaType !== null && filled($this->mediaId);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private static function fromInteractiveReply(array $payload): self
    {
        $reply = $payload['button_reply'] ?? $payload['list_reply'] ?? null;

        if (! is_array($reply)) {
            return new self(unrecognizedPress: true);
        }

        return new self(text: $reply['title'] ?? null, replyId: $reply['id'] ?? null);
    }

    /**
     * Meta delivers media as {id, mime_type, caption?}, sometimes nested
     * under the media type key ({image: {id, ...}}); accept both shapes.
     *
     * @param  array<string, mixed>  $payload
     */
    private static function fromMedia(array $payload, ListingMediaType $mediaType): self
    {
        $media = $payload[$mediaType->value] ?? $payload;

        return new self(
            text: $media['caption'] ?? $payload['caption'] ?? null,
            mediaType: $mediaType,
            mediaId: is_string($media['id'] ?? null) ? $media['id'] : null,
        );
    }
}
