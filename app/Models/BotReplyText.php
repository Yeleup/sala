<?php

namespace App\Models;

use App\Enums\BotReplyKey;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Переопределённый оператором встроенный ответ бота: одна строка на ключ.
 * Нет строки (или blank) — используется стандартный текст из BotReplyKey.
 */
#[Fillable(['key', 'text'])]
class BotReplyText extends Model
{
    /**
     * @return array{key: class-string<BotReplyKey>}
     */
    protected function casts(): array
    {
        return [
            'key' => BotReplyKey::class,
        ];
    }
}
