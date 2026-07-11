<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum ListingType: string implements HasLabel
{
    case Equipment = 'equipment';
    case Service = 'service';

    public function getLabel(): string
    {
        return match ($this) {
            self::Equipment => 'Техника',
            self::Service => 'Услуга',
        };
    }
}
