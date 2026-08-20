<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const string OLD_TEMPLATE = 'listings_renewal_batch';

    private const string NEW_TEMPLATE = 'several_listings_renewal';

    private const string OLD_VARIABLE = 'listings.expiring';

    private const array NEW_VARIABLES = ['listings.expiring_first', 'listings.expiring_rest'];

    /**
     * Переводит уже сохранённые сценарии пачечного опроса на новый шаблон.
     *
     * Без этого сценарий, опубликованный до переезда, продолжает ссылаться
     * на старый — и всё ещё утверждённый — шаблон listings_renewal_batch,
     * а снятая переменная listings.expiring подставляется прочерком: вне
     * окна поставщику уходит платное маркетинговое «У — скоро закончится
     * срок показа в поиске», опрос считается отправленным, и объявления
     * молча уезжают в архив. Отправка при этом не падает, так что
     * деградация на поштучные вопросы не срабатывает — состояние надо
     * убрать, а не пережить.
     *
     * Правятся и снимки версий: запуск закреплён за версией, и хотя его
     * сообщение уже ушло, оставлять в снимке ссылку на исчезающий шаблон
     * незачем — id вариантов кнопок не меняются, маршрутизация ответов не
     * затрагивается.
     */
    public function up(): void
    {
        $batchScenarioIds = DB::table('bot_scenarios')
            ->where('trigger', 'listings_expiring_batch')
            ->pluck('id');

        if ($batchScenarioIds->isEmpty()) {
            return;
        }

        DB::table('bot_scenarios')
            ->whereIn('id', $batchScenarioIds)
            ->get(['id', 'draft_definition', 'published_definition'])
            ->each(function (object $scenario): void {
                DB::table('bot_scenarios')->where('id', $scenario->id)->update([
                    'draft_definition' => $this->repointed($scenario->draft_definition),
                    'published_definition' => $this->repointed($scenario->published_definition),
                ]);
            });

        DB::table('bot_scenario_versions')
            ->whereIn('bot_scenario_id', $batchScenarioIds)
            ->get(['id', 'definition'])
            ->each(function (object $version): void {
                DB::table('bot_scenario_versions')->where('id', $version->id)->update([
                    'definition' => $this->repointed($version->definition),
                ]);
            });
    }

    public function down(): void
    {
        // Возврат к сожжённому шаблону смысла не имеет: Meta не отдаст ему
        // утилитарную категорию.
    }

    /**
     * Меняет ссылку на шаблон и сопоставление переменных у блоков, которые
     * ссылались на старый шаблон. Прочие блоки и графы не трогаются.
     */
    private function repointed(?string $definition): ?string
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
            if (($node['template_name'] ?? null) !== self::OLD_TEMPLATE) {
                continue;
            }

            $decoded['nodes'][$index]['template_name'] = self::NEW_TEMPLATE;

            // Новый шаблон несёт два {{n}}, а старый ключ больше не
            // существует: единственное осмысленное сопоставление — новое.
            if (in_array(self::OLD_VARIABLE, array_values($node['variables'] ?? []), true)
                || count($node['variables'] ?? []) !== count(self::NEW_VARIABLES)) {
                $decoded['nodes'][$index]['variables'] = self::NEW_VARIABLES;
            }

            $changed = true;
        }

        return $changed ? json_encode($decoded) : $definition;
    }
};
