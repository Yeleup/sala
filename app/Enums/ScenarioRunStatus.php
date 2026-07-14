<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum ScenarioRunStatus: string implements HasColor, HasLabel
{
    case Active = 'active';
    case Completed = 'completed';

    /** The run could not deliver its message (closed window, no approved template, transport error). */
    case Failed = 'failed';

    public function getLabel(): string
    {
        return match ($this) {
            self::Active => 'Ждёт ответа',
            self::Completed => 'Завершён',
            self::Failed => 'Ошибка отправки',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Active => 'warning',
            self::Completed => 'success',
            self::Failed => 'danger',
        };
    }
}
