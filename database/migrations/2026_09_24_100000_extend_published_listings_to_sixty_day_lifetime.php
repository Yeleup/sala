<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Разница между новым сроком показа (60 дней) и прежним (30).
     */
    private const int EXTRA_DAYS = 30;

    /**
     * Переводит уже опубликованные объявления на 60-дневный срок показа:
     * к текущему сроку добавляется разница, чтобы по новому правилу жили
     * все сразу, а не только публикации после выкладки.
     *
     * Исключение — объявления, которым опрос актуальности текущего цикла
     * уже ушёл (стоит отметка отправленного опроса): вопрос у поставщика
     * на телефоне, его ответ решает судьбу публикации, и цикл
     * заканчивается как есть. Сдвиг под заданным вопросом оставил бы
     * отметку висеть месяц: следующий опрос не ушёл бы, а кнопка
     * «Да, актуально» продлила бы публикацию раньше, чем её спросили бы
     * снова.
     *
     * Черновики, модерация, отклонённые и архив не трогаются: у них нет
     * идущего срока показа, новый срок они получат при публикации.
     */
    public function up(): void
    {
        DB::table('listings')
            ->where('status', 'published')
            ->whereNotNull('expires_at')
            ->whereNull('renewal_requested_at')
            ->select(['id', 'expires_at'])
            ->chunkById(500, function ($listings): void {
                foreach ($listings as $listing) {
                    DB::table('listings')->where('id', $listing->id)->update([
                        'expires_at' => Carbon::parse($listing->expires_at)->addDays(self::EXTRA_DAYS),
                    ]);
                }
            });
    }

    public function down(): void
    {
        // Отнять сдвиг точно уже нельзя: за это время часть публикаций
        // продлили, и их срок отсчитан заново от момента продления.
    }
};
