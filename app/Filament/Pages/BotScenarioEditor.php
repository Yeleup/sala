<?php

namespace App\Filament\Pages;

use App\Enums\AiTask;
use App\Enums\BotNodeType;
use App\Enums\BotScenarioTrigger;
use App\Enums\ListingType;
use App\Enums\ScenarioAction;
use App\Enums\ScenarioCondition;
use App\Enums\ScenarioMessageChannel;
use App\Enums\ScenarioVariable;
use App\Models\BotScenario;
use App\Models\WhatsappTemplate;
use App\Services\Bot\ScenarioValidator;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\HtmlString;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use UnitEnum;

/**
 * Visual constructor of the bot scenarios (docs/modules/bot-constructor.md):
 * a canvas of blocks connected by transitions. Besides the main dialog
 * the bot has run-based scenarios — auto-scenarios launched by system
 * events and broadcast scenarios launched by the operator; each is a
 * separate graph edited here. Admins edit the draft; contacts are
 * affected only after «Опубликовать сценарий» passes validation.
 */
class BotScenarioEditor extends Page
{
    protected static ?string $slug = 'bot-scenario';

    protected string $view = 'filament.pages.bot-scenario-editor';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShare;

    protected static string|UnitEnum|null $navigationGroup = 'Бот';

    protected static ?string $navigationLabel = 'Сценарии';

    protected static ?string $title = 'Сценарии бота';

    #[Url(as: 'scenario')]
    public ?int $scenarioId = null;

    public string $newScenarioName = '';

    public string $newScenarioTrigger = BotScenarioTrigger::NewCustomerRequest->value;

    public function mount(): void
    {
        $this->scenarioId = $this->scenario->id;
    }

    /**
     * The scenario being edited; defaults to the main dialog, which is
     * created with a lone Start block on the first visit.
     */
    #[Computed]
    public function scenario(): BotScenario
    {
        if ($this->scenarioId !== null) {
            $selected = BotScenario::query()->find($this->scenarioId);

            if ($selected !== null) {
                return $selected;
            }
        }

        return BotScenario::main() ?? BotScenario::query()->create([
            'name' => 'Главный сценарий',
            'trigger' => BotScenarioTrigger::InboundMessage,
            'draft_definition' => [
                'nodes' => [['id' => 'start', 'type' => 'start', 'x' => 80, 'y' => 220]],
                'edges' => [],
            ],
        ]);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, BotScenario>
     */
    #[Computed]
    public function scenarios()
    {
        return BotScenario::query()->orderBy('id')->get();
    }

    /**
     * Reference data the canvas editor needs for the block sidebar:
     * which blocks the scenario's trigger allows and the select options
     * for templates, variables, conditions and actions.
     *
     * @return array<string, mixed>
     */
    #[Computed]
    public function editorConfig(): array
    {
        $trigger = $this->scenario->trigger;

        return [
            'runBased' => $trigger->isRunBased(),
            'templates' => WhatsappTemplate::query()->orderBy('name')->get()
                ->map(fn (WhatsappTemplate $template): array => [
                    'name' => $template->name,
                    'label' => $template->name.' — '.$template->status->getLabel(),
                    'body' => (string) $template->body,
                ])
                ->values()
                ->all(),
            'variables' => collect(ScenarioVariable::cases())
                ->filter(fn (ScenarioVariable $variable): bool => $variable->allowedIn($trigger))
                ->map(fn (ScenarioVariable $variable): array => ['value' => $variable->value, 'label' => $variable->label()])
                ->values()
                ->all(),
            'conditions' => collect(ScenarioCondition::cases())
                ->filter(fn (ScenarioCondition $condition): bool => $condition->allowedIn($trigger))
                ->map(fn (ScenarioCondition $condition): array => ['value' => $condition->value, 'label' => $condition->label()])
                ->values()
                ->all(),
            'actions' => collect(ScenarioAction::cases())
                ->filter(fn (ScenarioAction $action): bool => $action->allowedIn($trigger))
                ->map(fn (ScenarioAction $action): array => ['value' => $action->value, 'label' => $action->label()])
                ->values()
                ->all(),
        ];
    }

    public function selectScenario(int $id): void
    {
        $this->redirect(static::getUrl(['scenario' => $id]));
    }

    public function createScenario(): void
    {
        $name = trim($this->newScenarioName);
        $trigger = BotScenarioTrigger::tryFrom($this->newScenarioTrigger);

        if ($name === '' || $trigger === null) {
            Notification::make()
                ->title('Укажите название и триггер нового сценария')
                ->danger()
                ->send();

            return;
        }

        $scenario = BotScenario::query()->create([
            'name' => $name,
            'trigger' => $trigger,
            'draft_definition' => [
                'nodes' => [['id' => 'start', 'type' => 'start', 'x' => 80, 'y' => 220]],
                'edges' => [],
            ],
        ]);

        $this->redirect(static::getUrl(['scenario' => $scenario->id]));
    }

    /**
     * Deleting is allowed only while the scenario has never been
     * published: a published scenario may have runs in flight whose
     * buttons must keep working.
     */
    public function deleteScenario(): void
    {
        $scenario = $this->scenario;

        if ($scenario->isPublished()) {
            Notification::make()
                ->title('Опубликованный сценарий удалить нельзя')
                ->body('По нему могут ждать ответа уже отправленные сообщения.')
                ->danger()
                ->send();

            return;
        }

        $scenario->delete();

        $this->redirect(static::getUrl());
    }

    /**
     * @param  array<string, mixed>  $definition
     */
    public function saveDraft(array $definition): void
    {
        $this->scenario->update(['draft_definition' => $this->cleanDefinition($definition)]);
        unset($this->scenario);

        Notification::make()
            ->title('Черновик сохранён')
            ->body('Пользователи увидят изменения после публикации сценария.')
            ->success()
            ->send();
    }

    /**
     * @param  array<string, mixed>  $definition
     */
    public function publish(array $definition): void
    {
        $scenario = $this->scenario;
        $clean = $this->cleanDefinition($definition);

        $scenario->update(['draft_definition' => $clean]);

        ['errors' => $errors, 'warnings' => $warnings] = app(ScenarioValidator::class)->validate($clean, $scenario->trigger);

        if ($errors !== []) {
            Notification::make()
                ->title('Сценарий не опубликован')
                ->body($this->issueList($errors))
                ->danger()
                ->persistent()
                ->send();
            unset($this->scenario);

            return;
        }

        $scenario->publishDraft();
        unset($this->scenario);

        $warnings = [...$warnings, ...$this->shadowedTriggerWarnings($scenario)];

        $notification = Notification::make()
            ->title('Сценарий опубликован')
            ->success();

        if ($warnings !== []) {
            $notification->body($this->issueList($warnings));
        }

        $notification->send();
    }

    /**
     * With several published scenarios on one trigger the oldest wins —
     * tell the operator this one will not actually be launched.
     *
     * @return list<string>
     */
    protected function shadowedTriggerWarnings(BotScenario $scenario): array
    {
        if (! $scenario->trigger->isRunBased()) {
            return [];
        }

        $active = BotScenario::publishedForTrigger($scenario->trigger);

        if ($active === null || $active->id === $scenario->id) {
            return [];
        }

        return ["Триггер «{$scenario->trigger->label()}» запускает более старый сценарий «{$active->name}» — этот опубликован, но запускаться не будет, пока существует тот."];
    }

    /**
     * Keep only the known graph keys and coerce their types — the payload
     * comes straight from the browser.
     *
     * @param  array<string, mixed>  $definition
     * @return array{nodes: list<array<string, mixed>>, edges: list<array{from: string, output: string, to: string}>}
     */
    protected function cleanDefinition(array $definition): array
    {
        $nodes = [];

        foreach (($definition['nodes'] ?? []) as $node) {
            if (! is_array($node) || blank($node['id'] ?? null)) {
                continue;
            }

            $clean = [
                'id' => (string) $node['id'],
                'type' => (string) ($node['type'] ?? ''),
                'x' => round((float) ($node['x'] ?? 0), 1),
                'y' => round((float) ($node['y'] ?? 0), 1),
            ];

            if (array_key_exists('text', $node)) {
                $clean['text'] = (string) $node['text'];
            }

            if (array_key_exists('button', $node)) {
                $clean['button'] = (string) $node['button'];
            }

            if (is_array($node['options'] ?? null)) {
                $clean['options'] = collect($node['options'])
                    ->filter(fn (mixed $option): bool => is_array($option) && filled($option['id'] ?? null))
                    ->map(fn (array $option): array => [
                        'id' => (string) $option['id'],
                        'title' => (string) ($option['title'] ?? ''),
                    ])
                    ->values()
                    ->all();
            }

            if ($clean['type'] === BotNodeType::AiInput->value) {
                $clean['task'] = AiTask::fromNode($node['task'] ?? null)->value;

                if (filled($node['listing_type'] ?? null) && ListingType::tryFrom((string) $node['listing_type']) !== null) {
                    $clean['listing_type'] = (string) $node['listing_type'];
                }
            }

            if ($clean['type'] === BotNodeType::Message->value) {
                $clean['channel'] = ScenarioMessageChannel::fromNode($node['channel'] ?? null)->value;
                $clean['template_name'] = trim((string) ($node['template_name'] ?? ''));
                $clean['variables'] = collect(is_array($node['variables'] ?? null) ? $node['variables'] : [])
                    ->filter(fn (mixed $variable): bool => is_string($variable) && $variable !== '')
                    ->values()
                    ->all();

                $timeoutHours = (int) ($node['timeout_hours'] ?? 0);

                if ($timeoutHours > 0) {
                    $clean['timeout_hours'] = $timeoutHours;
                }
            }

            if ($clean['type'] === BotNodeType::Condition->value) {
                $clean['condition'] = (string) ($node['condition'] ?? '');
            }

            if ($clean['type'] === BotNodeType::Action->value) {
                $clean['action'] = (string) ($node['action'] ?? '');
            }

            $nodes[] = $clean;
        }

        $edges = [];

        foreach (($definition['edges'] ?? []) as $edge) {
            if (! is_array($edge) || blank($edge['from'] ?? null) || blank($edge['output'] ?? null) || blank($edge['to'] ?? null)) {
                continue;
            }

            $edges[] = [
                'from' => (string) $edge['from'],
                'output' => (string) $edge['output'],
                'to' => (string) $edge['to'],
            ];
        }

        return ['nodes' => $nodes, 'edges' => $edges];
    }

    /**
     * @param  list<string>  $issues
     */
    protected function issueList(array $issues): HtmlString
    {
        return new HtmlString(
            collect($issues)->map(fn (string $issue): string => '• '.e($issue))->implode('<br>'),
        );
    }
}
