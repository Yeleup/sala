<?php

namespace App\Enums;

/**
 * What a «Запрос ввода (AI)» scenario block asks the AI assistant to do,
 * stored in the node's "task" property. Unknown or missing values fall
 * back to the default task so previously saved drafts keep working.
 */
enum AiTask: string
{
    case CollectListing = 'collect_listing';

    public static function fromNode(mixed $value): self
    {
        return (is_string($value) ? self::tryFrom($value) : null) ?? self::CollectListing;
    }
}
