<?php

namespace App\Services\Bot;

use App\Enums\MenuRouteKind;
use App\Enums\RouteConfidence;

/**
 * A typed destination MenuRouter resolved an unmatched menu message into.
 * $option carries where an Option route leads and is null for the other
 * two kinds, which need no target beyond their kind.
 */
final readonly class MenuRoute
{
    /**
     * @param  array{node_id: string, option_id: string}|null  $option
     */
    private function __construct(
        public MenuRouteKind $kind,
        public ?array $option,
        public RouteConfidence $confidence,
    ) {}

    /**
     * @param  array{node_id: string, option_id: string}  $option
     */
    public static function toOption(array $option, RouteConfidence $confidence): self
    {
        return new self(MenuRouteKind::Option, $option, $confidence);
    }

    public static function toResume(RouteConfidence $confidence): self
    {
        return new self(MenuRouteKind::Resume, null, $confidence);
    }

    public static function toServiceQuestion(RouteConfidence $confidence): self
    {
        return new self(MenuRouteKind::ServiceQuestion, null, $confidence);
    }
}
