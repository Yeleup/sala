<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum DereuCompanyStatus: string implements HasColor, HasLabel
{
    case Connected = 'connected';
    case Deactivated = 'deactivated';

    public function getLabel(): string
    {
        return match ($this) {
            self::Connected => 'Подключён',
            self::Deactivated => 'Отключён',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Connected => 'success',
            self::Deactivated => 'danger',
        };
    }
}
