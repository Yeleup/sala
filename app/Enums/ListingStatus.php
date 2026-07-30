<?php

namespace App\Enums;

use BackedEnum;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;
use Filament\Support\Icons\Heroicon;

enum ListingStatus: string implements HasColor, HasIcon, HasLabel
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

    /**
     * States, not the actions that lead to them: a draft is still being
     * written, a listing on moderation is waiting for someone.
     */
    public function getIcon(): string|BackedEnum|null
    {
        return match ($this) {
            self::Draft => Heroicon::OutlinedPencil,
            self::PendingModeration => Heroicon::OutlinedClock,
            self::Published => Heroicon::OutlinedCheckCircle,
            self::Rejected => Heroicon::OutlinedXCircle,
            self::Archived => Heroicon::OutlinedArchiveBox,
        };
    }
}
