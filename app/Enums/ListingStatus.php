<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum ListingStatus: string implements HasColor, HasLabel
{
    case Draft = 'draft';
    case PendingModeration = 'pending_moderation';
    case Published = 'published';
    case Rejected = 'rejected';
    case Archived = 'archived';

    public function getLabel(): string
    {
        return match ($this) {
            self::Draft => 'Черновик',
            self::PendingModeration => 'На модерации',
            self::Published => 'Опубликовано',
            self::Rejected => 'Отклонено',
            self::Archived => 'В архиве',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Draft, self::Archived => 'gray',
            self::PendingModeration => 'warning',
            self::Published => 'success',
            self::Rejected => 'danger',
        };
    }
}
