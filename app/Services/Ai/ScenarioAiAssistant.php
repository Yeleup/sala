<?php

namespace App\Services\Ai;

use App\Enums\AiOutcome;
use App\Enums\AiTask;
use App\Models\BotSession;
use App\Services\Bot\AiAssistant;
use App\Services\Bot\InboundMessage;

/**
 * The scenario engine's entry point into the AI module: routes an
 * «Запрос ввода (AI)» block to the handler for its configured task and
 * clears the session's working memory once the AI releases the contact.
 */
class ScenarioAiAssistant implements AiAssistant
{
    public function __construct(
        private readonly SupplierListingCollector $collector,
        private readonly CustomerSearchAssistant $customerSearch,
    ) {}

    public function start(BotSession $session, array $node): AiOutcome
    {
        return $this->settle($session, $this->handlerFor($node)->start($session, $node));
    }

    public function resume(BotSession $session, array $node, InboundMessage $message): AiOutcome
    {
        return $this->settle($session, $this->handlerFor($node)->resume($session, $node, $message));
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
