<?php

namespace App\Enums;

/**
 * Where a repair master works: in his own service shop, on-site at the
 * customer's, or both.
 */
enum RepairPlace: string
{
    case OwnService = 'own_service';
    case Travels = 'travels';
    case Both = 'both';

    public function label(): string
    {
        return match ($this) {
            self::OwnService => 'В своём сервисе',
            self::Travels => 'С выездом',
            self::Both => 'И так, и так',
        };
    }
}
