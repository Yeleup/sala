<?php

namespace App\Enums;

/**
 * Whether the money column of an attempt can be trusted: an estimate from
 * the stored tariff snapshot, or unknown because the model has no
 * configured price (never a silent zero).
 */
enum AiCostStatus: string
{
    case Estimated = 'estimated';
    case Unknown = 'unknown';
}
