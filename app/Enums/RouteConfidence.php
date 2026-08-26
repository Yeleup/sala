<?php

namespace App\Enums;

/**
 * How sure the AI navigator is that a free-text message names a menu
 * destination or the interrupted questionnaire — see
 * docs/modules/ai-assistant.md.
 */
enum RouteConfidence: string
{
    case High = 'high';
    case Medium = 'medium';
    case Low = 'low';

    /**
     * A missing or unrecognisable classification is treated as the least
     * confident reading: guessing a destination from a malformed answer
     * would be worse than asking nothing and continuing the current step.
     */
    public static function fromExtraction(mixed $value): self
    {
        return is_string($value) ? (self::tryFrom($value) ?? self::Low) : self::Low;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
