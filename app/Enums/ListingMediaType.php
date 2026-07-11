<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum ListingMediaType: string implements HasLabel
{
    case Photo = 'photo';
    case Audio = 'audio';

    public function getLabel(): string
    {
        return match ($this) {
            self::Photo => 'Фото',
            self::Audio => 'Аудио',
        };
    }
}
