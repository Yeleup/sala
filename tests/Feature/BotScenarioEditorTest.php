<?php

use App\Enums\BotScenarioTrigger;
use App\Filament\Pages\BotScenarioEditor;
use App\Models\BotScenario;
use App\Models\BotScenarioVersion;
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

test('every publication leaves an immutable version snapshot', function () {
    BotScenario::factory()->published()->create();

    Livewire::test(BotScenarioEditor::class)->call('publish', publishableDefinition());

    $scenario = BotScenario::sole();
    expect($scenario->versions()->count())->toBe(2)
        ->and(BotScenarioVersion::query()->where('version', 2)->sole()->definition)
        ->toBe($scenario->published_definition);
});

/**
 * Публикуемый run-based граф: Старт → сообщение с кнопкой → действие → конец.
 */
function publishableRunDefinition(): array
{
    return [
        'nodes' => [
            ['id' => 'start', 'type' => 'start', 'x' => 0, 'y' => 0],
            ['id' => 'blast', 'type' => 'message', 'text' => 'Обновите объявления', 'channel' => 'session',
                'x' => 300, 'y' => 0, 'options' => [['id' => 'update', 'title' => 'Обновить']]],
            ['id' => 'cta', 'type' => 'action', 'action' => 'send_cabinet_cta', 'x' => 600, 'y' => 0],
            ['id' => 'end', 'type' => 'end', 'x' => 900, 'y' => 0],
        ],
        'edges' => [
            ['from' => 'start', 'output' => 'continue', 'to' => 'blast'],
            ['from' => 'blast', 'output' => 'option:update', 'to' => 'cta'],
            ['from' => 'cta', 'output' => 'continue', 'to' => 'end'],
        ],
    ];
}

describe('несколько сценариев', function () {
    test('создание нового сценария с триггером и переход в его редактор', function () {
        BotScenario::factory()->create();

        Livewire::test(BotScenarioEditor::class)
            ->set('newScenarioName', 'Мой автосценарий')
            ->set('newScenarioTrigger', BotScenarioTrigger::ListingExpiring->value)
            ->call('createScenario')
            ->assertRedirect();

        $created = BotScenario::query()->where('name', 'Мой автосценарий')->sole();
        expect($created->trigger)->toBe(BotScenarioTrigger::ListingExpiring)
            ->and($created->draft_definition['nodes'][0]['type'])->toBe('start');
    });

    test('шаблон без зеркалированных кнопок (синхронизация Dereu) не блокирует публикацию', function () {
        BotScenario::factory()->create();
        // Синхронизация возвращает только BODY-компонент — состав кнопок неизвестен.
        \App\Models\WhatsappTemplate::factory()->approved()->create([
            'name' => 'renewal_poll',
            'body' => 'Объявление «{{1}}» ещё актуально?',
            'components' => [['type' => 'BODY', 'text' => 'Объявление «{{1}}» ещё актуально?']],
        ]);
        $scenario = BotScenario::factory()->trigger(BotScenarioTrigger::ListingExpiring)->create(['name' => 'Продление']);

        $definition = publishableRunDefinition();
        $definition['nodes'][1]['channel'] = 'adaptive';
        $definition['nodes'][1]['template_name'] = 'renewal_poll';
        $definition['nodes'][1]['variables'] = ['listing.category'];
        $definition['nodes'][2] = ['id' => 'cta', 'type' => 'action', 'action' => 'renew_listing', 'x' => 600, 'y' => 0];

        Livewire::test(BotScenarioEditor::class, ['scenarioId' => $scenario->id])
            ->call('publish', $definition)
            ->assertNotified('Сценарий опубликован');
    });

    test('явное несовпадение числа кнопок с шаблоном блокирует публикацию', function () {
        BotScenario::factory()->create();
        \App\Models\WhatsappTemplate::factory()->approved()->create([
            'name' => 'renewal_poll',
            'body' => 'Объявление «{{1}}» ещё актуально?',
            'components' => [
                ['type' => 'BODY', 'text' => 'Объявление «{{1}}» ещё актуально?'],
                ['type' => 'BUTTONS', 'buttons' => [
                    ['type' => 'QUICK_REPLY', 'text' => 'Да'],
                    ['type' => 'QUICK_REPLY', 'text' => 'Нет'],
                ]],
            ],
        ]);
        $scenario = BotScenario::factory()->trigger(BotScenarioTrigger::ListingExpiring)->create(['name' => 'Продление']);

        $definition = publishableRunDefinition();
        $definition['nodes'][1]['channel'] = 'adaptive';
        $definition['nodes'][1]['template_name'] = 'renewal_poll';
        $definition['nodes'][1]['variables'] = ['listing.category'];
        $definition['nodes'][2] = ['id' => 'cta', 'type' => 'action', 'action' => 'renew_listing', 'x' => 600, 'y' => 0];

        Livewire::test(BotScenarioEditor::class, ['scenarioId' => $scenario->id])
            ->call('publish', $definition)
            ->assertNotified('Сценарий не опубликован');

        expect($scenario->refresh()->published_version)->toBe(0);
    });

    test('run-based сценарий публикуется с блоками сообщения, действия и завершения', function () {
        BotScenario::factory()->create();
        $flow = BotScenario::factory()->trigger(BotScenarioTrigger::ListingExpiring)->create(['name' => 'Автосценарий']);

        Livewire::test(BotScenarioEditor::class, ['scenarioId' => $flow->id])
            ->call('publish', publishableRunDefinition())
            ->assertNotified('Сценарий опубликован');

        expect($flow->refresh()->published_version)->toBe(1);
    });

    test('блоки запусков запрещены в главном диалоге, а AI-блок — в run-based сценарии', function () {
        BotScenario::factory()->create();

        Livewire::test(BotScenarioEditor::class)
            ->call('publish', publishableRunDefinition())
            ->assertNotified('Сценарий не опубликован');

        $flow = BotScenario::factory()->trigger(BotScenarioTrigger::ListingExpiring)->create(['name' => 'Автосценарий']);
        $withAi = publishableRunDefinition();
        $withAi['nodes'][] = ['id' => 'ai', 'type' => 'ai', 'task' => 'collect_listing', 'x' => 0, 'y' => 300];

        Livewire::test(BotScenarioEditor::class, ['scenarioId' => $flow->id])
            ->call('publish', $withAi)
            ->assertNotified('Сценарий не опубликован');
    });

    test('опубликованный сценарий удалить нельзя, черновик — можно', function () {
        BotScenario::factory()->create();
        $published = BotScenario::factory()->trigger(BotScenarioTrigger::ListingExpiring)->published()->create(['name' => 'Опубликованная']);

        Livewire::test(BotScenarioEditor::class, ['scenarioId' => $published->id])
            ->call('deleteScenario')
            ->assertNotified('Опубликованный сценарий удалить нельзя');

        expect(BotScenario::query()->whereKey($published->id)->exists())->toBeTrue();

        $draft = BotScenario::factory()->trigger(BotScenarioTrigger::ListingExpiring)->create(['name' => 'Черновик']);

        Livewire::test(BotScenarioEditor::class, ['scenarioId' => $draft->id])
            ->call('deleteScenario')
            ->assertRedirect();

        expect(BotScenario::query()->whereKey($draft->id)->exists())->toBeFalse();
    });
});
