<?php

namespace App\Support;

/**
 * Picks the Russian noun form for a count: «1 объявление», «2 объявления»,
 * «5 объявлений». The teens are the trap — 11–14 take the many-form even
 * though they end in 1–4 — so the check on the last two digits comes
 * first.
 *
 * Illuminate's trans_choice cannot express that exception, and the
 * project keeps no lang/ catalogue: the rule lives here as plain code.
 */
class RussianPlural
{
    public static function choose(int $count, string $one, string $few, string $many): string
    {
        $count = abs($count);

        return match (true) {
            $count % 100 >= 11 && $count % 100 <= 14 => $many,
            $count % 10 === 1 => $one,
            $count % 10 >= 2 && $count % 10 <= 4 => $few,
            default => $many,
        };
    }
}
