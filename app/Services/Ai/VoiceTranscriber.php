<?php

namespace App\Services\Ai;

use App\Enums\AiOperationType;
use App\Services\Ai\Audit\AiAudit;
use Laravel\Ai\Transcription;

/**
 * Turns a downloaded WhatsApp voice message into text, recording the AI
 * call in the audit journal. Shared by the supplier collector and the
 * customer search (docs/modules/ai-assistant.md).
 *
 * Speech is expected only in Russian or Kazakh (possibly mixed within one
 * phrase), so the provider is given a context prompt instead of a hard
 * single-language code — the model still transcribes each word as spoken.
 */
class VoiceTranscriber
{
    private const LANGUAGE_HINT = 'Речь на русском или казахском языке, возможна смесь обоих языков в одной фразе.';

    public function __construct(private readonly AiAudit $audit) {}

    /**
     * @param  array{contact_id?: int|null, bot_session_id?: int|null, listing_id?: int|null}  $links
     */
    public function transcribe(string $contents, ?string $mimeType, array $links = []): string
    {
        return $this->audit->run(
            AiOperationType::Transcription,
            fn (): string => trim((string) Transcription::fromBase64(
                base64_encode($contents),
                $mimeType,
            )->withProviderOptions(['prompt' => self::LANGUAGE_HINT])->generate()),
            $links,
        );
    }
}
