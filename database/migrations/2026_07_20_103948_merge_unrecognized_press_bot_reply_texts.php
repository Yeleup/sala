<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Три встроенных ответа о нераспознанном нажатии кнопки объединены в один:
 * переопределение оператора для «вне диалога» переносится на новый ключ,
 * два других варианта больше не существуют и удаляются. down() возвращает
 * прежний ключ, но удалённые переопределения menu/AI невосстановимы.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('bot_reply_texts')
            ->where('key', 'unrecognized_press_idle')
            ->update(['key' => 'unrecognized_press']);

        DB::table('bot_reply_texts')
            ->whereIn('key', ['unrecognized_press_menu', 'unrecognized_press_ai'])
            ->delete();

        // BotReplyTexts кэширует переопределения навсегда — см. его flush().
        Cache::forget('bot-reply-texts');
    }

    public function down(): void
    {
        DB::table('bot_reply_texts')
            ->where('key', 'unrecognized_press')
            ->update(['key' => 'unrecognized_press_idle']);

        Cache::forget('bot-reply-texts');
    }
};
