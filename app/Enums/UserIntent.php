<?php

namespace App\Enums;

/**
 * What the last user message was about, as classified by the extraction
 * agents. The AI block holds the dialog turn, so this is how a person
 * leaves it in words — see docs/modules/ai-assistant.md.
 */
enum UserIntent: string
{
    /** The message belongs to the block's task: listing details, search requirements. */
    case Task = 'task';

    /** The person refused the task or asked for a different one. */
    case Abandoned = 'abandoned';

    /** A question about the service itself, not about the offer or the search. */
    case ServiceQuestion = 'service_question';

    /**
     * A missing or unknown value is an ordinary task message: the schema
     * enum already constrains the model, and guessing an exit from a
     * malformed answer would be worse than continuing.
     */
    public static function fromExtraction(mixed $value): self
    {
        return is_string($value) ? (self::tryFrom($value) ?? self::Task) : self::Task;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
