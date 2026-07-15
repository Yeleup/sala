<?php

namespace App\Enums;

/**
 * Whether the money column can be trusted: an estimate from the stored
 * tariff snapshot, or unknown because no price is configured (never a
 * silent zero). Shared by AI attempts (ai_attempts) and WhatsApp template
 * messages (channel_messages).
 */
enum AiCostStatus: string
{
    case Estimated = 'estimated';
    case Unknown = 'unknown';
}
