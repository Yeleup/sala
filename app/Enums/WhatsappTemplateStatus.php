<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Meta moderation status of a template. The source of truth is Meta:
 * updates arrive via the Dereu `template_status_update` webhook.
 */
enum WhatsappTemplateStatus: string implements HasColor, HasLabel
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';

    public function getLabel(): string
    {
        return match ($this) {
            self::Pending => 'На модерации Meta',
            self::Approved => 'Утверждён',
            self::Rejected => 'Отклонён',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Pending => 'warning',
            self::Approved => 'success',
            self::Rejected => 'danger',
        };
    }
}
