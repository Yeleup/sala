<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Сутки — шаг самого 30-дневного цикла: опрос уходит за сутки до конца
     * срока показа, и к следующему суточному прогону публикация уже
     * заархивирована, то есть вопрос перестал что-либо решать.
     */
    private const int TIMEOUT_HOURS = 24;

    /** @var list<string> */
    private const array RENEWAL_TRIGGERS = ['listing_expiring', 'listings_expiring_batch'];

    /**
     * Задаёт опросам актуальности срок ожидания ответа.
     *
     * Оба сценария продления публиковались без него: считалось, что
     * молчание и так уводит публикацию в архив по истечении срока, а
     * будить запуск незачем. Но без срока timeout_at остаётся пустым,
     * свип таймаутов такие запуски не видит вовсе, и «Ждёт ответа» с них
     * не снимается никогда — журнал запусков превращается в один
     * бесконечный этот статус, где промолчавший поставщик неотличим от
     * того, чей ответ действительно ещё ждут.
     *
     * Ветка таймаута по-прежнему никуда не подключена: воскрешать
     * заархивированное нечем, и по неподключённому выходу запуск просто
     * гаснет статусом «Не ответили». Нажатие кнопки после этого получает
     * встроенный текст «Срок вопроса вышел», зовущий в кабинет.
     *
     * Правятся и снимки версий: запуск закреплён за версией, и срок
     * ожидания читается именно из неё. Уже висящие запуски получают срок
     * задним числом — иначе новое поведение досталось бы только будущим
     * опросам, а нынешние висели бы вечно.
     */
    public function up(): void
    {
        $scenarioIds = DB::table('bot_scenarios')
            ->whereIn('trigger', self::RENEWAL_TRIGGERS)
            ->pluck('id');

        if ($scenarioIds->isEmpty()) {
            return;
        }

        DB::table('bot_scenarios')
            ->whereIn('id', $scenarioIds)
            ->get(['id', 'draft_definition', 'published_definition'])
            ->each(function (object $scenario): void {
                DB::table('bot_scenarios')->where('id', $scenario->id)->update([
                    'draft_definition' => $this->withTimeout($scenario->draft_definition),
                    'published_definition' => $this->withTimeout($scenario->published_definition),
                ]);
            });

        DB::table('bot_scenario_versions')
            ->whereIn('bot_scenario_id', $scenarioIds)
            ->get(['id', 'definition'])
            ->each(function (object $version): void {
                DB::table('bot_scenario_versions')->where('id', $version->id)->update([
                    'definition' => $this->withTimeout($version->definition),
                ]);
            });

        $this->deadlineWaitingRuns($scenarioIds->all());
    }

    public function down(): void
    {
        // Снимать срок ожидания незачем: без него запуск не гаснет никогда,
        // а это и был дефект.
    }

    /**
     * Проставляет срок ожидания запускам, которые уже стоят на вопросе:
     * ожидание началось, когда запуск встал на блок, — от него и считаем.
     * Свип таймаутов разберёт просроченные на ближайшем часовом прогоне.
     *
     * @param  list<int>  $scenarioIds
     */
    private function deadlineWaitingRuns(array $scenarioIds): void
    {
        DB::table('scenario_runs')
            ->whereIn('bot_scenario_id', $scenarioIds)
            ->where('status', 'active')
            ->whereNotNull('current_node_id')
            ->whereNull('timeout_at')
            ->get(['id', 'updated_at'])
            ->each(function (object $run): void {
                DB::table('scenario_runs')->where('id', $run->id)->update([
                    'timeout_at' => Carbon::parse($run->updated_at)->addHours(self::TIMEOUT_HOURS),
                ]);
            });
    }

    /**
     * Задаёт срок ожидания блокам сообщения с кнопками. Блок без кнопок
     * ничего не ждёт — срок на нём конструктор считает ошибкой; уже
     * заданный вручную срок остаётся как есть.
     */
    private function withTimeout(?string $definition): ?string
    {
        if ($definition === null) {
            return null;
        }

        $decoded = json_decode($definition, true);

        if (! is_array($decoded) || ! is_array($decoded['nodes'] ?? null)) {
            return $definition;
        }

        $changed = false;

        foreach ($decoded['nodes'] as $index => $node) {
            if (($node['type'] ?? null) !== 'message' || ($node['options'] ?? []) === []) {
                continue;
            }

            if ((int) ($node['timeout_hours'] ?? 0) > 0) {
                continue;
            }

            $decoded['nodes'][$index]['timeout_hours'] = self::TIMEOUT_HOURS;
            $changed = true;
        }

        return $changed ? json_encode($decoded) : $definition;
    }
};
