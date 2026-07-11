<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum CustomerRequestStatus: string implements HasColor, HasLabel
{
    case Pending = 'pending';
    case Accepted = 'accepted';
    case Declined = 'declined';

    public function getLabel(): string
    {
        return match ($this) {
            self::Pending => 'Ожидает ответа',
            self::Accepted => 'Согласие',
            self::Declined => 'Отказ',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Pending => 'warning',
            self::Accepted => 'success',
            self::Declined => 'danger',
        };
    }
}
