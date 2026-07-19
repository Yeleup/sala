<?php

namespace App\Services\Bot;

use App\Enums\BotNodeType;
use App\Enums\BotScenarioTrigger;
use App\Enums\ScenarioAction;
use App\Enums\ScenarioCondition;
use App\Enums\ScenarioMessageChannel;
use App\Enums\ScenarioVariable;
use App\Models\WhatsappTemplate;

/**
 * Publication-time validation of a scenario graph (docs/modules/bot-constructor.md).
 *
 * Errors block publication: WhatsApp limits exceeded, the Start block's
 * output is not connected, an interactive block has unconnected option
 * outputs, broken references, blocks that don't belong to the scenario's
 * trigger. Blocks unreachable from Start and not-yet-approved templates
 * are only warnings.
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
     * Which blocks each scenario kind may use: the main dialog runs
     * interactive menus and the AI; run-based scenarios orchestrate
     * proactive messages, conditions and domain actions.
     */
    private const array MAIN_DIALOG_TYPES = [
        BotNodeType::Start,
        BotNodeType::Text,
        BotNodeType::ButtonMenu,
        BotNodeType::ListMenu,
        BotNodeType::AiInput,
        BotNodeType::MyListings,
        BotNodeType::End,
    ];

    private const array RUN_BASED_TYPES = [
        BotNodeType::Start,
        BotNodeType::Text,
        BotNodeType::Message,
        BotNodeType::Condition,
        BotNodeType::Action,
        BotNodeType::MyListings,
        BotNodeType::End,
    ];

    /**
     * @param  array{nodes?: list<array<string, mixed>>, edges?: list<array<string, mixed>>}  $definition
     * @return array{errors: list<string>, warnings: list<string>}
     */
    public function validate(array $definition, BotScenarioTrigger $trigger = BotScenarioTrigger::InboundMessage): array
    {
        $detailed = $this->validateDetailed($definition, $trigger);

        return [
            'errors' => array_values(array_unique(array_column($detailed['errors'], 'message'))),
            'warnings' => array_values(array_unique(array_column($detailed['warnings'], 'message'))),
        ];
    }

    /**
     * То же, но каждая запись знает свой блок — для перехода к нему
     * по клику в проверке до публикации.
     *
     * @param  array{nodes?: list<array<string, mixed>>, edges?: list<array<string, mixed>>}  $definition
     * @return array{errors: list<array{message: string, node_id: string|null}>, warnings: list<array{message: string, node_id: string|null}>}
     */
    public function validateDetailed(array $definition, BotScenarioTrigger $trigger = BotScenarioTrigger::InboundMessage): array
    {
        $errors = [];
        $warnings = [];
        $nodes = collect($definition['nodes'] ?? []);
        $edges = collect($definition['edges'] ?? []);

        $issue = fn (string $message, ?string $nodeId = null): array => ['message' => $message, 'node_id' => $nodeId];

        $startNodes = $nodes->filter(fn (array $node): bool => ($node['type'] ?? null) === BotNodeType::Start->value);

        if ($startNodes->isEmpty()) {
            $errors[] = $issue('В сценарии нет блока «Старт».');
        } elseif ($startNodes->count() > 1) {
            $errors[] = $issue('Блок «Старт» должен быть единственным.');
        }

        foreach ($nodes as $node) {
            $nodeId = isset($node['id']) ? (string) $node['id'] : null;
            ['errors' => $nodeErrors, 'warnings' => $nodeWarnings] = $this->validateNode($node, $edges, $trigger);
            $errors = [...$errors, ...array_map(fn (string $message): array => $issue($message, $nodeId), $nodeErrors)];
            $warnings = [...$warnings, ...array_map(fn (string $message): array => $issue($message, $nodeId), $nodeWarnings)];
        }

        $nodeIds = $nodes->pluck('id')->filter()->all();

        foreach ($edges as $edge) {
            if (! in_array($edge['from'] ?? null, $nodeIds, true) || ! in_array($edge['to'] ?? null, $nodeIds, true)) {
                $errors[] = $issue('Связь ссылается на несуществующий блок.', in_array($edge['from'] ?? null, $nodeIds, true) ? (string) $edge['from'] : null);
            }
        }

        // Каждый выход ведёт максимум в один блок: клиент переназначает
        // связь при повторном соединении, сервер — последний рубеж.
        $edges
            ->groupBy(fn (array $edge): string => ($edge['from'] ?? '').'|'.($edge['output'] ?? ''))
            ->filter(fn ($group): bool => $group->count() > 1)
            ->each(function ($group) use ($nodes, $issue, &$errors): void {
                $from = $group->first()['from'] ?? '?';
                $node = $nodes->firstWhere('id', $from) ?? ['id' => $from];
                $errors[] = $issue("С одного выхода блока {$this->nodeLabel($node)} идёт больше одной связи.", (string) $from);
            });

        $unique = fn (array $issues): array => collect($issues)
            ->unique(fn (array $entry): string => $entry['message'].'|'.($entry['node_id'] ?? ''))
            ->values()
            ->all();

        return [
            'errors' => $unique($errors),
            'warnings' => $unique([
                ...$this->unreachableNodeWarnings($nodes->all(), $edges->all()),
                ...$warnings,
            ]),
        ];
    }

    /**
     * @param  array<string, mixed>  $node
     * @param  \Illuminate\Support\Collection<int, array<string, mixed>>  $edges
     * @return array{errors: list<string>, warnings: list<string>}
     */
    protected function validateNode(array $node, $edges, BotScenarioTrigger $trigger): array
    {
        $type = BotNodeType::tryFrom((string) ($node['type'] ?? ''));
        $label = $this->nodeLabel($node);

        if ($type === null) {
            return ['errors' => ["Блок {$label} имеет неизвестный тип."], 'warnings' => []];
        }

        $allowedTypes = $trigger->isRunBased() ? self::RUN_BASED_TYPES : self::MAIN_DIALOG_TYPES;

        if (! in_array($type, $allowedTypes, true)) {
            return [
                'errors' => ["Блок {$label} недоступен в сценарии с триггером «{$trigger->label()}»."],
                'warnings' => [],
            ];
        }

        $errors = [];
        $warnings = [];
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

        if ($type === BotNodeType::Message) {
            ['errors' => $messageErrors, 'warnings' => $messageWarnings] = $this->validateMessage($node, $label, $trigger, $hasOutput);
            $errors = [...$errors, ...$messageErrors];
            $warnings = [...$warnings, ...$messageWarnings];
        }

        if ($type === BotNodeType::Condition) {
            $errors = [...$errors, ...$this->validateCondition($node, $label, $trigger, $hasOutput)];
        }

        if ($type === BotNodeType::Action) {
            $errors = [...$errors, ...$this->validateAction($node, $label, $trigger, $hasOutput)];
        }

        return ['errors' => $errors, 'warnings' => $warnings];
    }

    /**
     * A «WhatsApp-сообщение» block: the session text, the template
     * reference for deliveries outside the 24-hour window, the {{n}}
     * variable mapping and the reply buttons with an output each.
     *
     * @param  array<string, mixed>  $node
     * @param  callable(string): bool  $hasOutput
     * @return array{errors: list<string>, warnings: list<string>}
     */
    protected function validateMessage(array $node, string $label, BotScenarioTrigger $trigger, callable $hasOutput): array
    {
        $errors = [];
        $warnings = [];

        $channelRaw = $node['channel'] ?? null;
        $channel = ScenarioMessageChannel::fromNode($channelRaw);

        if (filled($channelRaw) && ScenarioMessageChannel::tryFrom((string) $channelRaw) === null) {
            $errors[] = "У блока {$label} неизвестный канал отправки.";
        }

        // Template-sourced channels take the wording from the template
        // body, so an own text is required only for the session channel.
        if (! $channel->usesTemplate() && blank($node['text'] ?? null)) {
            $errors[] = "У блока {$label} не заполнен текст сообщения.";
        }

        $errors = [...$errors, ...$this->validateOptions($node, BotNodeType::ButtonMenu, $label, $hasOutput, requireOptions: false)];

        $variables = array_values($node['variables'] ?? []);

        foreach ($variables as $variable) {
            $known = ScenarioVariable::tryFrom((string) $variable);

            if ($known === null) {
                $errors[] = "У блока {$label} неизвестная переменная «{$variable}».";
            } elseif (! $known->allowedIn($trigger)) {
                $errors[] = "Переменная «{$known->label()}» блока {$label} недоступна в сценарии с триггером «{$trigger->label()}».";
            }
        }

        $timeoutHours = (int) ($node['timeout_hours'] ?? 0);
        $options = array_values($node['options'] ?? []);

        if ($hasOutput(ScenarioDefinition::OUTPUT_TIMEOUT) && $timeoutHours <= 0) {
            $errors[] = "У блока {$label} подключен выход таймаута, но не задан срок ожидания.";
        }

        if ($timeoutHours > 0 && $options === []) {
            $errors[] = "У блока {$label} задан таймаут, но нет кнопок — блок не ждёт ответа.";
        }

        if (! $channel->usesTemplate()) {
            return ['errors' => $errors, 'warnings' => $warnings];
        }

        $templateName = trim((string) ($node['template_name'] ?? ''));

        if ($templateName === '') {
            $errors[] = "У блока {$label} не выбран шаблон — он задаёт текст сообщения.";

            return ['errors' => $errors, 'warnings' => $warnings];
        }

        $template = WhatsappTemplate::query()->where('name', $templateName)->first();

        // Runtime refuses to send without the template anyway, so a
        // not-yet-registered template must not block publication — e.g.
        // the default scenarios install before the registry fills.
        if ($template === null) {
            $warnings[] = "Шаблон «{$templateName}» блока {$label} не найден в реестре шаблонов — пока он не добавлен, сообщение не уйдёт (шаблон задаёт текст).";

            return ['errors' => $errors, 'warnings' => $warnings];
        }

        if (! $template->isApproved()) {
            $warnings[] = "Шаблон «{$templateName}» блока {$label} ещё не утверждён Meta — до утверждения отправка возможна только в открытое 24-часовое окно.";
        }

        $placeholders = $this->templatePlaceholderCount($template);

        if ($placeholders !== count($variables)) {
            $errors[] = sprintf(
                'У блока %s сопоставлено %d переменных, а шаблон «%s» ожидает %d.',
                $label,
                count($variables),
                $templateName,
                $placeholders,
            );
        }

        $templateButtons = $this->templateQuickReplyCount($template);

        if ($templateButtons !== null && $templateButtons !== count($options)) {
            $errors[] = sprintf(
                'У блока %s %d кнопок, а у шаблона «%s» %d кнопок быстрого ответа — выходы не совпадут.',
                $label,
                count($options),
                $templateName,
                $templateButtons,
            );
        }

        return ['errors' => $errors, 'warnings' => $warnings];
    }

    /**
     * @param  array<string, mixed>  $node
     * @param  callable(string): bool  $hasOutput
     * @return list<string>
     */
    protected function validateCondition(array $node, string $label, BotScenarioTrigger $trigger, callable $hasOutput): array
    {
        $errors = [];
        $condition = ScenarioCondition::tryFrom((string) ($node['condition'] ?? ''));

        if ($condition === null) {
            $errors[] = "У блока {$label} не выбрано условие.";
        } elseif (! $condition->allowedIn($trigger)) {
            $errors[] = "Условие «{$condition->label()}» блока {$label} недоступно в сценарии с триггером «{$trigger->label()}».";
        }

        foreach ([ScenarioDefinition::OUTPUT_YES => 'Да', ScenarioDefinition::OUTPUT_NO => 'Нет'] as $output => $name) {
            if (! $hasOutput($output)) {
                $errors[] = "В блоке {$label} не подключен выход «{$name}».";
            }
        }

        return $errors;
    }

    /**
     * @param  array<string, mixed>  $node
     * @param  callable(string): bool  $hasOutput
     * @return list<string>
     */
    protected function validateAction(array $node, string $label, BotScenarioTrigger $trigger, callable $hasOutput): array
    {
        $action = ScenarioAction::tryFrom((string) ($node['action'] ?? ''));

        if ($action === null) {
            return ["У блока {$label} не выбрано действие."];
        }

        if (! $action->allowedIn($trigger)) {
            return ["Действие «{$action->label()}» блока {$label} недоступно в сценарии с триггером «{$trigger->label()}»."];
        }

        // Устаревшая связь: выход «не выполнено» остался от прежнего
        // действия, а у текущего исхода «не выполнено» не бывает.
        if ($hasOutput(ScenarioDefinition::OUTPUT_SKIPPED) && ! $action->hasPrecondition()) {
            return ["У блока {$label} подключен выход «Не выполнено», но действие «{$action->label()}» не может остаться невыполненным."];
        }

        return [];
    }

    /**
     * The number of {{n}} placeholders the template body expects.
     */
    protected function templatePlaceholderCount(WhatsappTemplate $template): int
    {
        preg_match_all('/\{\{(\d+)\}\}/', (string) $template->body, $matches);

        return $matches[1] === [] ? 0 : max(array_map(intval(...), $matches[1]));
    }

    /**
     * The number of quick-reply buttons of the template, or null when it
     * is unknown. The Dereu sync mirrors templates without their BUTTONS
     * component, so a missing component means «состав кнопок неизвестен»,
     * not «кнопок нет» — the count is enforced only when the registry
     * positively knows it.
     */
    protected function templateQuickReplyCount(WhatsappTemplate $template): ?int
    {
        $components = $template->components;

        if (! is_array($components)) {
            return null;
        }

        foreach ($components as $component) {
            if (! is_array($component) || strtoupper((string) ($component['type'] ?? '')) !== 'BUTTONS') {
                continue;
            }

            $buttons = $component['buttons'] ?? [];

            if (! is_array($buttons)) {
                return null;
            }

            return count(array_filter(
                $buttons,
                fn (mixed $button): bool => is_array($button) && strtoupper((string) ($button['type'] ?? '')) === 'QUICK_REPLY',
            ));
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $node
     * @param  callable(string): bool  $hasOutput
     * @return list<string>
     */
    protected function validateOptions(array $node, BotNodeType $type, string $label, callable $hasOutput, bool $requireOptions = true): array
    {
        $errors = [];
        $options = array_values($node['options'] ?? []);
        $isButtons = $type === BotNodeType::ButtonMenu;
        $limit = $isButtons ? self::MAX_BUTTONS : self::MAX_LIST_ROWS;
        $titleLimit = $isButtons ? self::MAX_BUTTON_TITLE_LENGTH : self::MAX_LIST_ROW_TITLE_LENGTH;
        $kind = $isButtons ? 'кнопок' : 'элементов списка';

        if ($options === [] && $requireOptions) {
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
     * @return list<array{message: string, node_id: string|null}>
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
                $warnings[] = [
                    'message' => "Блок {$this->nodeLabel($node)} недостижим от «Старта».",
                    'node_id' => isset($node['id']) ? (string) $node['id'] : null,
                ];
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
