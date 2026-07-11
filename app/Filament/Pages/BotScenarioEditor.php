<?php

namespace App\Filament\Pages;

use App\Enums\AiTask;
use App\Enums\BotNodeType;
use App\Enums\ListingType;
use App\Models\BotScenario;
use App\Services\Bot\ScenarioValidator;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\HtmlString;
use Livewire\Attributes\Computed;

/**
 * Visual constructor of the bot's single main scenario
 * (docs/modules/bot-constructor.md): a canvas of blocks connected by
 * transitions. Admins edit the draft; contacts are affected only after
 * «Опубликовать сценарий» passes validation.
 */
class BotScenarioEditor extends Page
{
    protected static ?string $slug = 'bot-scenario';

    protected string $view = 'filament.pages.bot-scenario-editor';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShare;

    protected static ?string $navigationLabel = 'Сценарий бота';

    protected static ?string $title = 'Сценарий бота';

    public function mount(): void
    {
        $this->scenario;
    }

    /**
     * The single main scenario; created with a lone Start block on the
     * first visit.
     */
    #[Computed]
    public function scenario(): BotScenario
    {
        return BotScenario::main() ?? BotScenario::query()->create([
            'name' => 'Главный сценарий',
            'draft_definition' => [
                'nodes' => [['id' => 'start', 'type' => 'start', 'x' => 80, 'y' => 220]],
                'edges' => [],
            ],
        ]);
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

        ['errors' => $errors, 'warnings' => $warnings] = app(ScenarioValidator::class)->validate($clean);

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

        $notification = Notification::make()
            ->title('Сценарий опубликован')
            ->success();

        if ($warnings !== []) {
            $notification->body($this->issueList($warnings));
        }

        $notification->send();
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
