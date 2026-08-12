<?php

namespace App\Enums;

/**
 * The licence a driver/operator holds. «Other» covers certificates the
 * two common kinds do not: crane operator permits and the like.
 */
enum LicenceType: string
{
    case DriverLicence = 'driver_licence';
    case TractorOperator = 'tractor_operator';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::DriverLicence => 'Водительское',
            self::TractorOperator => 'Тракторист-машинист',
            self::Other => 'Другой документ',
        };
    }
}
