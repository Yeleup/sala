<?php

namespace App\Enums;

/**
 * Where a MenuRoute points a contact whose message matched none of a
 * menu's own buttons: one of the scenario graph's own destinations
 * (Option), the questionnaire it interrupted (Resume), or a question about
 * the service itself (ServiceQuestion) — see docs/modules/ai-assistant.md.
 */
enum MenuRouteKind
{
    case Option;
    case Resume;
    case ServiceQuestion;
}
