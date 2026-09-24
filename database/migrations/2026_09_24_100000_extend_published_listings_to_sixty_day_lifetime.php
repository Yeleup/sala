<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Снимок сдвинутых публикаций: какая публикация получила разницу и
     * какой срок был у неё до сдвига.
     */
    private const string SNAPSHOT_TABLE = 'listing_lifetime_extensions';

    /**
     * Переводит уже опубликованные объявления на 60-дневный срок показа:
     * к текущему сроку добавляется разница в 30 дней, чтобы по новому
     * правилу жили все сразу, а не только публикации после выкладки.
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
     *
     * Сдвиг обязан быть однократным, а тело миграции может выполниться
     * дважды: Laravel записывает миграцию в журнал уже после фиксации её
     * транзакции, и сбой между этими шагами повторит up() при следующем
     * запуске — «+30» сверху превратил бы цикл в 90 дней. Поэтому сдвиг
     * идёт от снимка, а не от текущего срока: снимок набирается один раз
     * в той же транзакции, что и сдвиг, а срок ставится как «срок из
     * снимка + 30» только тем публикациям, чей срок всё ещё равен снимку.
     * Уже сдвинутая — как и продлённая после выкладки — публикация под это
     * условие не попадает. Снимок заодно показывает, кому и от какого
     * срока добавлены дни, и позволяет откатить сдвиг точно.
     */
    public function up(): void
    {
        if (! Schema::hasTable(self::SNAPSHOT_TABLE)) {
            Schema::create(self::SNAPSHOT_TABLE, function (Blueprint $table): void {
                $table->foreignId('listing_id')->primary()->constrained()->cascadeOnDelete();
                $table->timestamp('previous_expires_at');
            });

            DB::table(self::SNAPSHOT_TABLE)->insertUsing(
                ['listing_id', 'previous_expires_at'],
                DB::table('listings')
                    ->select(['id', 'expires_at'])
                    ->where('status', 'published')
                    ->whereNotNull('expires_at')
                    ->whereNull('renewal_requested_at'),
            );
        }

        DB::update(sprintf(
            "update listings set expires_at = snapshot.previous_expires_at + interval '30 days'
             from %s as snapshot
             where listings.id = snapshot.listing_id
               and listings.expires_at = snapshot.previous_expires_at
               and listings.status = 'published'
               and listings.renewal_requested_at is null",
            self::SNAPSHOT_TABLE,
        ));
    }

    /**
     * Возвращает прежний срок тем публикациям, у которых он всё ещё
     * сдвинут ровно на разницу; продлённые после выкладки получили свой
     * срок заново и остаются как есть.
     */
    public function down(): void
    {
        if (! Schema::hasTable(self::SNAPSHOT_TABLE)) {
            return;
        }

        DB::update(sprintf(
            "update listings set expires_at = snapshot.previous_expires_at
             from %s as snapshot
             where listings.id = snapshot.listing_id
               and listings.expires_at = snapshot.previous_expires_at + interval '30 days'",
            self::SNAPSHOT_TABLE,
        ));

        Schema::drop(self::SNAPSHOT_TABLE);
    }
};
