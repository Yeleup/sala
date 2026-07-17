<?php

namespace App\Services\Ai;

use App\Enums\AiOutcome;
use App\Enums\AiTask;
use App\Models\BotSession;
use App\Services\Bot\AiAssistant;
use App\Services\Bot\InboundMessage;
use App\Services\DereuMediaDownloader;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The scenario engine's entry point into the AI module: routes an
 * «Запрос ввода (AI)» block to the handler for its configured task and
 * clears the session's working memory once the AI releases the contact.
 *
 * Voice messages are resolved here, before any task handler runs: the
 * audio is always downloaded and transcribed (with an audit record), so
 * every AI task works with ready text the same way.
 */
class ScenarioAiAssistant implements AiAssistant
{
    public function __construct(
        private readonly SupplierListingCollector $collector,
        private readonly CustomerSearchAssistant $customerSearch,
        private readonly DereuMediaDownloader $mediaDownloader,
        private readonly VoiceTranscriber $transcriber,
    ) {}

    public function start(BotSession $session, array $node): AiOutcome
    {
        return $this->settle($session, $this->handlerFor($node)->start($session, $node));
    }

    public function resume(BotSession $session, array $node, InboundMessage $message): AiOutcome
    {
        $message = $this->resolveVoice($session, $message);

        return $this->settle($session, $this->handlerFor($node)->resume($session, $node, $message));
    }

    /**
     * A failure (media download, AI provider) leaves the message
     * unresolved — the handler then asks for text instead of the bot
     * going silent, and no clarification attempt is spent.
     */
    private function resolveVoice(BotSession $session, InboundMessage $message): InboundMessage
    {
        if (! $message->isVoice()) {
            return $message;
        }

        try {
            $download = $this->mediaDownloader->download((string) $message->mediaId);

            $transcription = $this->transcriber->transcribe($download['contents'], $download['mime_type'], [
                'contact_id' => $session->contact_id,
                'bot_session_id' => $session->id,
            ]);

            return $message->withVoice($download['contents'], $transcription);
        } catch (Throwable $e) {
            Log::warning('Voice message could not be downloaded or transcribed for the AI block.', [
                'bot_session_id' => $session->id,
                'error' => $e->getMessage(),
            ]);

            return $message;
        }
    }

    private function handlerFor(array $node): SupplierListingCollector|CustomerSearchAssistant
    {
        return match (AiTask::fromNode($node['task'] ?? null)) {
            AiTask::CollectListing => $this->collector,
            AiTask::CustomerSearch => $this->customerSearch,
        };
    }

    private function settle(BotSession $session, AiOutcome $outcome): AiOutcome
    {
        if ($outcome === AiOutcome::Completed && $session->state !== null) {
            $session->update(['state' => null]);
        }

        return $outcome;
    }
}
