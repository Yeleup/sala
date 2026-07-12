<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Meta template categories; they define moderation rules and pricing.
 */
enum WhatsappTemplateCategory: string implements HasLabel
{
    case Authentication = 'authentication';
    case Utility = 'utility';
    case Marketing = 'marketing';

    public function getLabel(): string
    {
        return match ($this) {
            self::Authentication => 'Аутентификация',
            self::Utility => 'Утилитарный',
            self::Marketing => 'Маркетинг',
        };
    }
}
