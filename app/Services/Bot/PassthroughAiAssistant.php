<?php

namespace App\Services\Bot;

use App\Enums\AiOutcome;
use App\Models\BotSession;

/**
 * Stand-in that completes immediately, so the flow falls through the AI
 * block's "continue" output without collecting anything. Bound in tests
 * that exercise the engine without the real assistant.
 */
class PassthroughAiAssistant implements AiAssistant
{
    public function start(BotSession $session, array $node): AiOutcome
    {
        return AiOutcome::Completed;
    }

    public function resume(BotSession $session, array $node, InboundMessage $message): AiOutcome
    {
        return AiOutcome::Completed;
    }
}
