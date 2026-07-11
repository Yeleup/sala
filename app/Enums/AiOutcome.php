<?php

namespace App\Enums;

/**
 * Result of handing a dialog step over to the AI assistant (see
 * docs/modules/ai-assistant.md). While InProgress the AI keeps the turn
 * and the scenario waits at the AI node; Completed releases the contact
 * through the node's "continue" output.
 */
enum AiOutcome
{
    case InProgress;
    case Completed;
}
