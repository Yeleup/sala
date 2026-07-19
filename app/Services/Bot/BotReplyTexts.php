<?php

namespace App\Services\Bot;

use App\Enums\BotReplyKey;
use App\Models\BotReplyText;
use Illuminate\Support\Facades\Cache;

/**
 * Читает встроенные ответы бота с учётом переопределений оператора
 * (страница «Ответы бота»). Кэш — один ключ на весь набор, сбрасывается
 * при сохранении; прод-стор общий для Octane- и queue-воркеров, поэтому
 * инвалидация видна всем процессам. Класс stateless — Octane-safe.
 */
class BotReplyTexts
{
    public const string CACHE_KEY = 'bot-reply-texts';

    public function get(BotReplyKey $key): string
    {
        return $this->overrides()[$key->value] ?? $key->default();
    }

    public function flush(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * @return array<string, string> переопределения; blank отфильтрован
     */
    protected function overrides(): array
    {
        return Cache::rememberForever(self::CACHE_KEY, fn (): array => BotReplyText::query()
            ->pluck('text', 'key')
            ->map(fn (string $text): string => trim($text))
            ->filter(fn (string $text): bool => $text !== '')
            ->all());
    }
}
