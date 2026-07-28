<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Where a listing came from. Listings drafted before the origin was
 * recorded carry none of these — their origin reads as unknown, which is
 * honest: until then an operator-typed listing was indistinguishable
 * from one the bot collected.
 */
enum ListingOrigin: string implements HasColor, HasLabel
{
    case Chat = 'chat';
    case Operator = 'operator';

    public function getLabel(): string
    {
        return match ($this) {
            self::Chat => 'Из чата',
            self::Operator => 'Завёл оператор',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Chat => 'gray',
            self::Operator => 'info',
        };
    }
}
