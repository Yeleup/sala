<?php

namespace App\Services\Bot;

use App\Enums\BotNodeType;

/**
 * Publication-time validation of a scenario graph (docs/modules/bot-constructor.md).
 *
 * Errors block publication: WhatsApp limits exceeded, the Start block's
 * output is not connected, an interactive block has unconnected option
 * outputs, broken references. Blocks unreachable from Start are only a
 * warning.
 */
class ScenarioValidator
{
    public const int MAX_BUTTONS = 3;

    public const int MAX_LIST_ROWS = 10;

    /** WhatsApp limit on a reply button title. */
    public const int MAX_BUTTON_TITLE_LENGTH = 20;

    /** WhatsApp limit on a list row title. */
    public const int MAX_LIST_ROW_TITLE_LENGTH = 24;

    /**
     * @param  array{nodes?: list<array<string, mixed>>, edges?: list<array<string, mixed>>}  $definition
     * @return array{errors: list<string>, warnings: list<string>}
     */
    public function validate(array $definition): array
    {
        $errors = [];
        $nodes = collect($definition['nodes'] ?? []);
        $edges = collect($definition['edges'] ?? []);

        $startNodes = $nodes->filter(fn (array $node): bool => ($node['type'] ?? null) === BotNodeType::Start->value);

        if ($startNodes->isEmpty()) {
            $errors[] = 'В сценарии нет блока «Старт».';
        } elseif ($startNodes->count() > 1) {
            $errors[] = 'Блок «Старт» должен быть единственным.';
        }

        foreach ($nodes as $node) {
            $errors = [...$errors, ...$this->validateNode($node, $edges)];
        }

        $nodeIds = $nodes->pluck('id')->filter()->all();

        foreach ($edges as $edge) {
            if (! in_array($edge['from'] ?? null, $nodeIds, true) || ! in_array($edge['to'] ?? null, $nodeIds, true)) {
                $errors[] = 'Связь ссылается на несуществующий блок.';
            }
        }

        // Каждый выход ведёт максимум в один блок: клиент переназначает
        // связь при повторном соединении, сервер — последний рубеж.
        $edges
            ->groupBy(fn (array $edge): string => ($edge['from'] ?? '').'|'.($edge['output'] ?? ''))
            ->filter(fn ($group): bool => $group->count() > 1)
            ->each(function ($group) use ($nodes, &$errors): void {
                $from = $group->first()['from'] ?? '?';
                $node = $nodes->firstWhere('id', $from) ?? ['id' => $from];
                $errors[] = "С одного выхода блока {$this->nodeLabel($node)} идёт больше одной связи.";
            });

        return [
            'errors' => array_values(array_unique($errors)),
            'warnings' => $this->unreachableNodeWarnings($nodes->all(), $edges->all()),
        ];
    }

    /**
     * @param  array<string, mixed>  $node
     * @param  \Illuminate\Support\Collection<int, array<string, mixed>>  $edges
     * @return list<string>
     */
    protected function validateNode(array $node, $edges): array
    {
        $type = BotNodeType::tryFrom((string) ($node['type'] ?? ''));
        $label = $this->nodeLabel($node);

        if ($type === null) {
            return ["Блок {$label} имеет неизвестный тип."];
        }

        $errors = [];
        $hasOutput = fn (string $output): bool => $edges->contains(
            fn (array $edge): bool => ($edge['from'] ?? null) === ($node['id'] ?? null) && ($edge['output'] ?? null) === $output,
        );

        if ($type === BotNodeType::Start && ! $hasOutput(ScenarioDefinition::OUTPUT_CONTINUE)) {
            $errors[] = 'Выход блока «Старт» не подключен.';
        }

        if (in_array($type, [BotNodeType::Text, BotNodeType::ButtonMenu, BotNodeType::ListMenu, BotNodeType::MyListings], true)
            && blank($node['text'] ?? null)) {
            $errors[] = "У блока {$label} не заполнен текст сообщения.";
        }

        if (in_array($type, [BotNodeType::ButtonMenu, BotNodeType::ListMenu], true)) {
            $errors = [...$errors, ...$this->validateOptions($node, $type, $label, $hasOutput)];
        }

        return $errors;
    }

    /**
     * @param  array<string, mixed>  $node
     * @param  callable(string): bool  $hasOutput
     * @return list<string>
     */
    protected function validateOptions(array $node, BotNodeType $type, string $label, callable $hasOutput): array
    {
        $errors = [];
        $options = array_values($node['options'] ?? []);
        $isButtons = $type === BotNodeType::ButtonMenu;
        $limit = $isButtons ? self::MAX_BUTTONS : self::MAX_LIST_ROWS;
        $titleLimit = $isButtons ? self::MAX_BUTTON_TITLE_LENGTH : self::MAX_LIST_ROW_TITLE_LENGTH;
        $kind = $isButtons ? 'кнопок' : 'элементов списка';

        if ($options === []) {
            $errors[] = "У блока {$label} нет ни одного варианта ответа.";
        }

        if (count($options) > $limit) {
            $errors[] = "У блока {$label} больше {$limit} {$kind} — лимит WhatsApp.";
        }

        foreach ($options as $option) {
            $title = trim((string) ($option['title'] ?? ''));

            if ($title === '') {
                $errors[] = "У блока {$label} есть вариант без названия.";
            } elseif (mb_strlen($title) > $titleLimit) {
                $errors[] = "Название «{$title}» в блоке {$label} длиннее {$titleLimit} символов — лимит WhatsApp.";
            }

            if (filled($option['id'] ?? null) && ! $hasOutput(ScenarioDefinition::optionOutput($option['id']))) {
                $errors[] = "В блоке {$label} не подключен выход варианта «{$title}».";
            }
        }

        return $errors;
    }

    /**
     * @param  list<array<string, mixed>>  $nodes
     * @param  list<array<string, mixed>>  $edges
     * @return list<string>
     */
    protected function unreachableNodeWarnings(array $nodes, array $edges): array
    {
        $startId = null;

        foreach ($nodes as $node) {
            if (($node['type'] ?? null) === BotNodeType::Start->value) {
                $startId = $node['id'] ?? null;
                break;
            }
        }

        if ($startId === null) {
            return [];
        }

        $reachable = [$startId => true];
        $queue = [$startId];

        while ($queue !== []) {
            $current = array_shift($queue);

            foreach ($edges as $edge) {
                $to = $edge['to'] ?? null;

                if (($edge['from'] ?? null) === $current && $to !== null && ! isset($reachable[$to])) {
                    $reachable[$to] = true;
                    $queue[] = $to;
                }
            }
        }

        $warnings = [];

        foreach ($nodes as $node) {
            if (! isset($reachable[$node['id'] ?? ''])) {
                $warnings[] = "Блок {$this->nodeLabel($node)} недостижим от «Старта».";
            }
        }

        return $warnings;
    }

    /**
     * @param  array<string, mixed>  $node
     */
    protected function nodeLabel(array $node): string
    {
        $text = trim((string) ($node['text'] ?? ''));

        if ($text !== '') {
            return '«'.str($text)->limit(30).'»';
        }

        return '«'.($node['id'] ?? '?').'»';
    }
}
