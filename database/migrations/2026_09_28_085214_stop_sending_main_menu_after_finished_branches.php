<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const string MAIN_DIALOG_TRIGGER = 'inbound_message';

    /** Blocks a new dialog passes through without stopping on its way to the menu. */
    private const array PASS_THROUGH_TYPES = ['text'];

    private const array MENU_TYPES = ['buttons', 'list'];

    /** Same order of magnitude as the engine's own step cap. */
    private const int MAX_WALK_STEPS = 20;

    /**
     * Перестаёт слать главное меню вслед за завершившейся веткой в уже
     * сохранённом главном диалоге.
     *
     * С августа выход «Продолжить» всех AI-блоков и блока «Мои
     * объявления» вёл в главное меню, и оно приходило следом за каждой
     * завершающей репликой: «Готово! Объявление ушло на проверку…» и тут
     * же «Что вас интересует?». Связи живут в графе сценария, а не в
     * коде, поэтому новое поведение опубликованному сценарию даёт только
     * правка самого графа.
     *
     * Меняются ровно типовые связи — выход «Продолжить» AI-блока или
     * блока «Мои объявления», ведущий прямо в меню, с которого начинается
     * новый диалог. У AI-блока такая связь переносится на выход «В меню»:
     * просьба о меню по-прежнему ведёт туда же, куда вела. У блока «Мои
     * объявления» связь снимается — просить меню там нечем. Всё
     * остальное остаётся как есть: тексты, кнопки, расположение блоков,
     * прочие связи и «Продолжить», который оператор сам направил в другой
     * блок.
     *
     * Правятся черновик и опубликованная схема. Снимки версий не
     * трогаются: главный диалог их не читает — контактов ведёт
     * опубликованная схема, а за снимками закреплены только запуски
     * сценариев-уведомлений. Номер версии не растёт, а в отпечаток шага
     * связи не входят, так что ждущие сейчас на меню или в анкете
     * продолжают с того же места.
     */
    public function up(): void
    {
        DB::table('bot_scenarios')
            ->where('trigger', self::MAIN_DIALOG_TRIGGER)
            ->get(['id', 'draft_definition', 'published_definition'])
            ->each(function (object $scenario): void {
                DB::table('bot_scenarios')->where('id', $scenario->id)->update([
                    'draft_definition' => $this->withoutMenuAfterBranches($scenario->draft_definition),
                    'published_definition' => $this->withoutMenuAfterBranches($scenario->published_definition),
                ]);
            });
    }

    public function down(): void
    {
        // Возвращать меню вслед за завершающей репликой незачем: оно
        // противоречило ей и стоило отдельного сообщения. Оператор, которому
        // оно нужно, подключает выход «Продолжить» в конструкторе сам.
    }

    private function withoutMenuAfterBranches(?string $definition): ?string
    {
        if ($definition === null) {
            return null;
        }

        $decoded = json_decode($definition, true);

        if (! is_array($decoded) || ! is_array($decoded['nodes'] ?? null) || ! is_array($decoded['edges'] ?? null)) {
            return $definition;
        }

        $types = [];

        foreach ($decoded['nodes'] as $node) {
            if (is_array($node) && isset($node['id'])) {
                $types[(string) $node['id']] = (string) ($node['type'] ?? '');
            }
        }

        $entryMenus = $this->entryMenus($types, $decoded['edges']);

        if ($entryMenus === []) {
            return $definition;
        }

        $edges = [];
        $changed = false;

        foreach ($decoded['edges'] as $edge) {
            if (! is_array($edge) || ! $this->leadsBranchToMenu($edge, $types, $entryMenus)) {
                $edges[] = $edge;

                continue;
            }

            $changed = true;
            $from = (string) $edge['from'];

            // «Мои объявления» просить меню не умеет; AI-блок, у которого
            // выход «В меню» уже подключён, в переносе не нуждается.
            if ($types[$from] !== 'ai' || $this->hasOutput($decoded['edges'], $from, 'menu')) {
                continue;
            }

            $edges[] = [...$edge, 'output' => 'menu'];
        }

        if (! $changed) {
            return $definition;
        }

        $decoded['edges'] = $edges;

        return json_encode($decoded);
    }

    /**
     * Типовая связь: «Продолжить» AI-блока или блока «Мои объявления»,
     * ведущий прямо в меню, с которого начинается новый диалог.
     *
     * @param  array<string, mixed>  $edge
     * @param  array<string, string>  $types
     * @param  list<string>  $entryMenus
     */
    private function leadsBranchToMenu(array $edge, array $types, array $entryMenus): bool
    {
        return ($edge['output'] ?? null) === 'continue'
            && in_array($types[(string) ($edge['from'] ?? '')] ?? null, ['ai', 'my_listings'], true)
            && in_array((string) ($edge['to'] ?? ''), $entryMenus, true);
    }

    /**
     * Меню, на котором останавливается новый диалог, — по обоим выходам
     * «Старта»: первому обращению и повторному. Путь идёт только через
     * текстовые блоки: ветка, стоящая на самом пути к меню, типовым
     * возвратом в него не является, и её связи остаются нетронутыми.
     *
     * @param  array<string, string>  $types
     * @param  list<mixed>  $edges
     * @return list<string>
     */
    private function entryMenus(array $types, array $edges): array
    {
        $startId = array_search('start', $types, true);

        if ($startId === false) {
            return [];
        }

        $menus = [];

        foreach (['returning', 'continue'] as $output) {
            $nodeId = $this->target($edges, (string) $startId, $output);

            for ($steps = 0; $steps < self::MAX_WALK_STEPS && $nodeId !== null; $steps++) {
                $type = $types[$nodeId] ?? null;

                if (in_array($type, self::MENU_TYPES, true)) {
                    $menus[] = $nodeId;

                    break;
                }

                if (! in_array($type, self::PASS_THROUGH_TYPES, true)) {
                    break;
                }

                $nodeId = $this->target($edges, $nodeId, 'continue');
            }
        }

        return array_values(array_unique($menus));
    }

    /**
     * @param  list<mixed>  $edges
     */
    private function target(array $edges, string $from, string $output): ?string
    {
        foreach ($edges as $edge) {
            if (is_array($edge) && ($edge['from'] ?? null) === $from && ($edge['output'] ?? null) === $output) {
                return isset($edge['to']) ? (string) $edge['to'] : null;
            }
        }

        return null;
    }

    /**
     * @param  list<mixed>  $edges
     */
    private function hasOutput(array $edges, string $from, string $output): bool
    {
        return $this->target($edges, $from, $output) !== null;
    }
};
