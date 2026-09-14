<?php

namespace App\Enums;

/**
 * Result of handing a dialog step over to the AI assistant (see
 * docs/modules/ai-assistant.md). While InProgress the AI keeps the turn
 * and the scenario waits at the AI node; Completed releases the contact
 * through the node's "continue" output; Back releases them one level up —
 * to the menu whose option leads into the node — and only where no such
 * menu exists falls through "continue" like Completed.
 */
enum AiOutcome
{
    case InProgress;
    case Completed;
    case Back;
}
