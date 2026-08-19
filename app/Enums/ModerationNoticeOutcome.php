<?php

namespace App\Enums;

/**
 * What happened to the moderation verdict notification. Skipped is not a
 * failure: the supplier's 24-hour window was closed and the operator
 * chose not to spend a paid template — the verdict itself still stands.
 */
enum ModerationNoticeOutcome
{
    case Delivered;
    case Skipped;
    case Failed;
}
