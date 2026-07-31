<?php

namespace App\Support;

use DateTimeInterface;
use Illuminate\Support\Carbon;

/**
 * Operator-facing time: storage stays in UTC, the display timezone
 * (config: app.display_timezone) applies only when a human reads the
 * clock — chat bubbles, day separators, per-day report groupings.
 */
final class DisplayTime
{
    public static function timezone(): string
    {
        return (string) config('app.display_timezone', 'UTC');
    }

    public static function local(DateTimeInterface $moment): Carbon
    {
        return Carbon::instance($moment)->timezone(self::timezone());
    }
}
