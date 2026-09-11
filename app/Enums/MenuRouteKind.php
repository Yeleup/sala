<?php

namespace App\Enums;

/**
 * What the engine does with a message that matched none of a menu's own
 * buttons: move the contact somewhere (Option, Resume), answer without
 * moving them (ServiceQuestion, Decline, HumanHandoff, Greeting), or say
 * nothing at all (Acknowledgement) — see docs/modules/ai-assistant.md.
 *
 * Deliberately narrower than MenuIntent: «не понял» has no kind, because
 * the router answers it with null, and null is the contract's own way of
 * saying «behave exactly as you did before the navigator existed».
 */
enum MenuRouteKind
{
    case Option;
    case Resume;
    case ServiceQuestion;

    /** Nothing is asked — the bot says nothing back and lets the dialog close. */
    case Acknowledgement;

    /** Nothing is needed — the bot says goodbye once and closes the dialog. */
    case Decline;

    /** A live person is wanted — the bot says so and stops repeating itself. */
    case HumanHandoff;

    /** A hello and nothing else — the bot shows what it can do. */
    case Greeting;
}
