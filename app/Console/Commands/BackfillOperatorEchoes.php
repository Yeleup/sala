<?php

namespace App\Console\Commands;

use App\Models\DereuWebhookEvent;
use App\Services\OperatorEchoJournal;
use Illuminate\Console\Command;

/**
 * Brings the operator's own messages into the journal for the period they
 * were being stored and ignored.
 *
 * The redispatch sweeper cannot do it: it only reaches back a day, and the
 * oldest of these events is a month and a half old.
 *
 * Deliberately does not turn on the «operator is handling this» pause —
 * these conversations finished long ago, and paying attention to them now
 * would silence the bot for contacts nobody is talking to.
 */
class BackfillOperatorEchoes extends Command
{
    protected $signature = 'dereu:backfill-operator-echoes {--dry-run : Только показать, сколько сообщений появится в переписках}';

    protected $description = 'Записать в журнал канала сообщения оператора из приложения WhatsApp Business, накопленные до включения их обработки';

    public function handle(OperatorEchoJournal $journal): int
    {
        $events = DereuWebhookEvent::query()
            ->where('event', 'business_app_message_echo')
            ->whereNull('processed_at')
            ->orderBy('id')
            ->get();

        if ($events->isEmpty()) {
            $this->info('Необработанных сообщений оператора нет.');

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $this->info("Будет разобрано событий: {$events->count()}.");

            return self::SUCCESS;
        }

        $recorded = 0;
        $alreadyThere = 0;
        $skipped = 0;
        $foreign = 0;

        foreach ($events as $event) {
            // Та же проверка, что и у джобы: тестовый номер делится между
            // проектами, и эхо соседней компании завело бы в переписки
            // чужой контакт и чужой разговор.
            if (! $event->belongsToCurrentCompany()) {
                $foreign++;
                $event->update(['processed_at' => now()]);

                continue;
            }

            $entry = $journal->record($event);

            if ($entry === null) {
                $skipped++;
            } elseif ($entry->wasRecentlyCreated) {
                $recorded++;
            } else {
                $alreadyThere++;
            }

            $event->update(['processed_at' => now()]);
        }

        $this->info("Записано сообщений: {$recorded}. Уже были в переписке: {$alreadyThere}. Пропущено событий без пригодного эха: {$skipped}. Чужой компании: {$foreign}.");

        return self::SUCCESS;
    }
}
