<?php

namespace App\Services\Bot;

use App\Enums\AiOutcome;
use App\Models\Contact;

/**
 * Handoff contract between the scenario engine and the AI assistant
 * (docs/modules/ai-assistant.md): the «Запрос ввода (AI)» block delegates
 * the dialog turn here.
 *
 * start() fires when the flow enters an AI block; resume() — for every
 * following inbound message while the contact waits at that block. A
 * Completed outcome releases the contact through the block's "continue"
 * output.
 */
interface AiAssistant
{
    /**
     * @param  array<string, mixed>  $node
     */
    public function start(Contact $contact, array $node): AiOutcome;

    /**
     * @param  array<string, mixed>  $node
     */
    public function resume(Contact $contact, array $node, InboundMessage $message): AiOutcome;
}
