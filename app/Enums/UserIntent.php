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

    /** The person asks for the main menu or another section. */
    case MenuRequested = 'menu';

    /**
     * Not about the search at all: a greeting, a meaningless string of
     * characters, a remark unrelated to it. Only the customer search offers
     * it — see searchValues(): the listing collector keeps its four.
     */
    case OffTopic = 'off_topic';

    /**
     * A missing or unknown value is an ordinary task message: the schema
     * enum already constrains the model, and guessing an exit from a
     * malformed answer would be worse than continuing.
     */
    public static function fromExtraction(mixed $value): self
    {
        return is_string($value) ? (self::tryFrom($value) ?? self::Task) : self::Task;
    }

    /**
     * The intents the listing extraction may return. «Not about the
     * search» has no place there: a supplier's message is listing data
     * unless it leaves, and the collector keeps its own exits.
     *
     * @return list<string>
     */
    public static function listingValues(): array
    {
        return array_column(array_filter(
            self::cases(),
            fn (self $intent): bool => $intent !== self::OffTopic,
        ), 'value');
    }

    /**
     * The intents the search query extraction may return.
     *
     * @return list<string>
     */
    public static function searchValues(): array
    {
        return array_column(self::cases(), 'value');
    }
}
