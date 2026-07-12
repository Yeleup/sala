<?php

use App\Filament\Pages\BotScenarioEditor;
use App\Models\BotScenario;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

/**
 * Публикуемый граф: Старт → меню (оба выхода подключены) → текстовые ветки.
 */
function publishableDefinition(): array
{
    return [
        'nodes' => [
            ['id' => 'start', 'type' => 'start', 'x' => 0, 'y' => 0],
            ['id' => 'menu', 'type' => 'buttons', 'text' => 'Кто вы?', 'x' => 300, 'y' => 0, 'options' => [
                ['id' => 'supplier', 'title' => 'Поставщик'],
                ['id' => 'customer', 'title' => 'Заказчик'],
            ]],
            ['id' => 'supplier_branch', 'type' => 'text', 'text' => 'Ветка поставщика', 'x' => 600, 'y' => 0],
            ['id' => 'customer_branch', 'type' => 'text', 'text' => 'Ветка заказчика', 'x' => 600, 'y' => 200],
        ],
        'edges' => [
            ['from' => 'start', 'output' => 'continue', 'to' => 'menu'],
            ['from' => 'menu', 'output' => 'option:supplier', 'to' => 'supplier_branch'],
            ['from' => 'menu', 'output' => 'option:customer', 'to' => 'customer_branch'],
        ],
    ];
}

test('guests are redirected to the panel login', function () {
    auth()->logout();

    $this->get(BotScenarioEditor::getUrl())->assertRedirect();
});

test('the first visit creates the single main scenario with a start block', function () {
    $this->get(BotScenarioEditor::getUrl())->assertSuccessful();

    $scenario = BotScenario::sole();
    expect($scenario->name)->toBe('Главный сценарий')
        ->and($scenario->published_version)->toBe(0)
        ->and($scenario->draft_definition['nodes'])->toHaveCount(1)
        ->and($scenario->draft_definition['nodes'][0]['type'])->toBe('start');
});

test('a repeat visit does not create a second scenario', function () {
    $this->get(BotScenarioEditor::getUrl())->assertSuccessful();
    $this->get(BotScenarioEditor::getUrl())->assertSuccessful();

    expect(BotScenario::count())->toBe(1);
});

test('saving a draft persists the graph without touching the published version', function () {
    $scenario = BotScenario::factory()->published()->create();

    Livewire::test(BotScenarioEditor::class)
        ->call('saveDraft', publishableDefinition())
        ->assertNotified('Черновик сохранён');

    $scenario->refresh();
    expect($scenario->draft_definition['nodes'])->toHaveCount(4)
        ->and($scenario->published_version)->toBe(1)
        ->and($scenario->published_definition)->not->toBe($scenario->draft_definition);
});

test('unknown keys are stripped from the saved draft while positions are kept', function () {
    BotScenario::factory()->create();

    $definition = publishableDefinition();
    $definition['nodes'][1]['evil'] = '<script>';
    $definition['edges'][] = ['from' => 'menu', 'to' => 'supplier_branch']; // без output — мусор
    $definition['junk'] = 'x';

    Livewire::test(BotScenarioEditor::class)->call('saveDraft', $definition);

    $draft = BotScenario::sole()->draft_definition;
    expect($draft)->toHaveKeys(['nodes', 'edges'])->not->toHaveKey('junk')
        ->and($draft['nodes'][1])->not->toHaveKey('evil')
        ->and($draft['nodes'][1]['x'])->toBe(300)
        ->and($draft['edges'])->toHaveCount(3);
});

test('publishing a valid graph applies the draft and bumps the version', function () {
    BotScenario::factory()->create();

    Livewire::test(BotScenarioEditor::class)
        ->call('publish', publishableDefinition())
        ->assertNotified('Сценарий опубликован');

    $scenario = BotScenario::sole();
    expect($scenario->published_version)->toBe(1)
        ->and($scenario->published_at)->not->toBeNull()
        ->and($scenario->published_definition)->toBe($scenario->draft_definition);
});

test('validation errors block publication but keep the draft', function () {
    BotScenario::factory()->create();

    $definition = publishableDefinition();
    $definition['edges'] = array_slice($definition['edges'], 1); // выход «Старта» не подключен

    Livewire::test(BotScenarioEditor::class)
        ->call('publish', $definition)
        ->assertNotified('Сценарий не опубликован');

    $scenario = BotScenario::sole();
    expect($scenario->published_version)->toBe(0)
        ->and($scenario->published_definition)->toBeNull()
        ->and($scenario->draft_definition['nodes'])->toHaveCount(4);
});

test('two edges from one output block publication', function () {
    BotScenario::factory()->create();

    $definition = publishableDefinition();
    $definition['edges'][] = $definition['edges'][0]; // дубль связи с одного выхода

    Livewire::test(BotScenarioEditor::class)
        ->call('publish', $definition)
        ->assertNotified('Сценарий не опубликован');

    expect(BotScenario::sole()->published_version)->toBe(0);
});

test('unreachable blocks do not block publication', function () {
    BotScenario::factory()->create();

    $definition = publishableDefinition();
    $definition['nodes'][] = ['id' => 'orphan', 'type' => 'text', 'text' => 'Сирота', 'x' => 0, 'y' => 400];

    Livewire::test(BotScenarioEditor::class)
        ->call('publish', $definition)
        ->assertNotified('Сценарий опубликован');

    expect(BotScenario::sole()->published_version)->toBe(1);
});

test('a republication increments the version further', function () {
    BotScenario::factory()->published()->create();

    Livewire::test(BotScenarioEditor::class)->call('publish', publishableDefinition());

    expect(BotScenario::sole()->published_version)->toBe(2);
});
