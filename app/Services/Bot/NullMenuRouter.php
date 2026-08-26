<?php

namespace App\Services\Bot;

use App\Models\BotSession;

/**
 * Stand-in that never routes anywhere, so the flow falls straight through
 * to the engine's own fallback behaviour. Bound in tests that exercise the
 * engine without the real router — mirrors PassthroughAiAssistant.
 */
class NullMenuRouter implements MenuRouter
{
    /**
     * @param  array<string, mixed>  $node
     */
    public function route(BotSession $session, ScenarioDefinition $definition, array $node, InboundMessage $message): ?MenuRoute
    {
        return null;
    }
}
