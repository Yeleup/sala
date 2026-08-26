<?php

namespace App\Services\Bot;

use App\Models\BotSession;

/**
 * Classifies a message that matched none of a menu node's own options into
 * a typed MenuRoute — the AI navigator's decision point for what to do
 * with the contact's free text instead of repeating the menu.
 *
 * Null means «behave exactly as today»: the caller (BotEngine, task 4)
 * falls all the way through to its existing fallback behaviour and must
 * never invent one of its own for the null case — a route is only ever
 * something to act on, never something to compensate for.
 */
interface MenuRouter
{
    /**
     * @param  array<string, mixed>  $node
     */
    public function route(BotSession $session, ScenarioDefinition $definition, array $node, InboundMessage $message): ?MenuRoute;
}
