<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** @var list<string> */
    private const array RENEWAL_TRIGGERS = ['listing_expiring', 'listings_expiring_batch'];

    /**
     * Прежняя фраза типовых сценариев продления и её замена.
     *
     * @var array<string, string>
     */
    private const array PHRASES = [
        'будет показываться ещё 30 дней' => 'будет показываться ещё 60 дней',
        'будут показываться ещё 30 дней' => 'будут показываться ещё 60 дней',
    ];

    /**
     * Срок показа стал 60 дней, а сохранённые сценарии продления всё ещё
     * отвечают поставщику «будет показываться ещё 30 дней»: текст задан
     * при установке сценария и живёт в его графе, а не в коде.
     *
     * Меняется только сама фраза о сроке — текст, который оператор
     * переписал по-своему, остаётся как есть. Правятся черновик,
     * опубликованная версия и снимки версий: запуск закреплён за версией
     * и отвечает текстом из неё.
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
                    'draft_definition' => $this->withNewLifetime($scenario->draft_definition),
                    'published_definition' => $this->withNewLifetime($scenario->published_definition),
                ]);
            });

        DB::table('bot_scenario_versions')
            ->whereIn('bot_scenario_id', $scenarioIds)
            ->get(['id', 'definition'])
            ->each(function (object $version): void {
                DB::table('bot_scenario_versions')->where('id', $version->id)->update([
                    'definition' => $this->withNewLifetime($version->definition),
                ]);
            });
    }

    public function down(): void
    {
        // Возвращать фразу о 30 днях незачем: срок показа ей больше не
        // соответствует.
    }

    private function withNewLifetime(?string $definition): ?string
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
            if (! is_string($node['text'] ?? null)) {
                continue;
            }

            $text = strtr($node['text'], self::PHRASES);

            if ($text !== $node['text']) {
                $decoded['nodes'][$index]['text'] = $text;
                $changed = true;
            }
        }

        return $changed ? json_encode($decoded) : $definition;
    }
};
