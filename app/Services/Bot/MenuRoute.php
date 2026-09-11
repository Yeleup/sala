<?php

namespace App\Services\Bot;

use App\Enums\MenuRouteKind;
use App\Enums\RouteConfidence;

/**
 * A typed outcome MenuRouter resolved an unmatched menu message into.
 * $option carries where an Option route leads and is null for every other
 * kind, which needs no target beyond the kind itself — several of them
 * move the contact nowhere at all.
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

    public static function toAcknowledgement(RouteConfidence $confidence): self
    {
        return new self(MenuRouteKind::Acknowledgement, null, $confidence);
    }

    public static function toDecline(RouteConfidence $confidence): self
    {
        return new self(MenuRouteKind::Decline, null, $confidence);
    }

    public static function toHumanHandoff(RouteConfidence $confidence): self
    {
        return new self(MenuRouteKind::HumanHandoff, null, $confidence);
    }

    public static function toGreeting(RouteConfidence $confidence): self
    {
        return new self(MenuRouteKind::Greeting, null, $confidence);
    }
}
