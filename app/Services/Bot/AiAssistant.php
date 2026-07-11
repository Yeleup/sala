<?php

namespace App\Services\Bot;

use App\Enums\AiOutcome;
use App\Models\BotSession;

/**
 * Handoff contract between the scenario engine and the AI assistant
 * (docs/modules/ai-assistant.md): the «Запрос ввода (AI)» block delegates
 * the dialog turn here.
 *
 * start() fires when the flow enters an AI block; resume() — for every
 * following inbound message while the contact waits at that block. A
 * Completed outcome releases the contact through the block's "continue"
 * output. The session carries the contact and the assistant's working
 * memory (BotSession::$state).
 */
interface AiAssistant
{
    /**
     * @param  array<string, mixed>  $node
     */
    public function start(BotSession $session, array $node): AiOutcome;

    /**
     * @param  array<string, mixed>  $node
     */
    public function resume(BotSession $session, array $node, InboundMessage $message): AiOutcome;
}
