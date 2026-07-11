<?php

namespace App\Services\Bot;

use App\Enums\AiOutcome;
use App\Models\Contact;

/**
 * Stand-in until the AI assistant module is implemented: completes
 * immediately, so the flow falls through the AI block's "continue"
 * output without collecting anything.
 */
class PassthroughAiAssistant implements AiAssistant
{
    public function start(Contact $contact, array $node): AiOutcome
    {
        return AiOutcome::Completed;
    }

    public function resume(Contact $contact, array $node, InboundMessage $message): AiOutcome
    {
        return AiOutcome::Completed;
    }
}
