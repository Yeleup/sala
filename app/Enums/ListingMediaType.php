<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum ListingMediaType: string implements HasLabel
{
    case Photo = 'photo';
    case Audio = 'audio';

    /** A driver's licence photo: never rendered publicly. */
    case Document = 'document';

    public function getLabel(): string
    {
        return match ($this) {
            self::Photo => 'Фото',
            self::Audio => 'Аудио',
            self::Document => 'Документ',
        };
    }
}
