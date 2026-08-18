<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum CustomerRequestStatus: string implements HasColor, HasLabel
{
    case Pending = 'pending';
    case Accepted = 'accepted';
    case Declined = 'declined';

    /**
     * Закрыта без решения поставщика: он не ответил за отведённый срок,
     * уведомление не удалось доставить, либо оператор снял ожидание.
     * Не блокирует повторную заявку — в отличие от Pending.
     */
    case Expired = 'expired';

    public function getLabel(): string
    {
        return match ($this) {
            self::Pending => 'Ожидает ответа',
            self::Accepted => 'Согласие',
            self::Declined => 'Отказ',
            self::Expired => 'Без ответа',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Pending => 'warning',
            self::Accepted => 'success',
            self::Declined => 'danger',
            self::Expired => 'gray',
        };
    }
}
