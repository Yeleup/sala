<?php

namespace App\Enums;

/**
 * How a «WhatsApp-сообщение» scenario block delivers its message with
 * respect to the 24-hour session window (docs/modules/whatsapp-integration.md).
 */
enum ScenarioMessageChannel: string
{
    /** A free session message inside the window, the approved template outside it. */
    case Adaptive = 'adaptive';

    /** Session message only — fails outside the window. */
    case SessionOnly = 'session';

    /** Always the approved template. */
    case TemplateOnly = 'template';

    public static function fromNode(mixed $value): self
    {
        return (is_string($value) ? self::tryFrom($value) : null) ?? self::Adaptive;
    }

    public function usesTemplate(): bool
    {
        return $this !== self::SessionOnly;
    }

    public function label(): string
    {
        return match ($this) {
            self::Adaptive => 'Адаптивно: сессия или шаблон',
            self::SessionOnly => 'Только сессионное',
            self::TemplateOnly => 'Только шаблон',
        };
    }
}
