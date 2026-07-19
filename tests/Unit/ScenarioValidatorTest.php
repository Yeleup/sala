<?php

use App\Enums\BotScenarioTrigger;
use App\Services\Bot\ScenarioValidator;

/**
 * Старт → меню (2 кнопки, оба выхода подключены) → текстовые ветки.
 */
function validScenarioDefinition(): array
{
    return [
        'nodes' => [
            ['id' => 'start', 'type' => 'start'],
            ['id' => 'menu', 'type' => 'buttons', 'text' => 'Кто вы?', 'options' => [
                ['id' => 'supplier', 'title' => 'Поставщик'],
                ['id' => 'customer', 'title' => 'Заказчик'],
            ]],
            ['id' => 'supplier_branch', 'type' => 'text', 'text' => 'Ветка поставщика'],
            ['id' => 'customer_branch', 'type' => 'text', 'text' => 'Ветка заказчика'],
        ],
        'edges' => [
            ['from' => 'start', 'output' => 'continue', 'to' => 'menu'],
            ['from' => 'menu', 'output' => 'option:supplier', 'to' => 'supplier_branch'],
            ['from' => 'menu', 'output' => 'option:customer', 'to' => 'customer_branch'],
        ],
    ];
}

function validateScenario(array $definition, BotScenarioTrigger $trigger = BotScenarioTrigger::InboundMessage): array
{
    return (new ScenarioValidator)->validate($definition, $trigger);
}

/**
 * Run-based граф «Новая заявка»: сообщение с кнопкой → действие.
 */
function validRunDefinition(): array
{
    return [
        'nodes' => [
            ['id' => 'start', 'type' => 'start'],
            ['id' => 'poll', 'type' => 'message', 'text' => 'Возьмёте заказ?', 'channel' => 'session', 'options' => [
                ['id' => 'accept', 'title' => 'Согласиться'],
            ]],
            ['id' => 'do_accept', 'type' => 'action', 'action' => 'accept_request'],
            ['id' => 'done', 'type' => 'text', 'text' => 'Передадим заказчику.'],
            ['id' => 'skipped_text', 'type' => 'text', 'text' => 'Заявка уже решена.'],
        ],
        'edges' => [
            ['from' => 'start', 'output' => 'continue', 'to' => 'poll'],
            ['from' => 'poll', 'output' => 'option:accept', 'to' => 'do_accept'],
            ['from' => 'do_accept', 'output' => 'continue', 'to' => 'done'],
            ['from' => 'do_accept', 'output' => 'skipped', 'to' => 'skipped_text'],
        ],
    ];
}

test('a valid graph produces no errors and no warnings', function () {
    expect(validateScenario(validScenarioDefinition()))->toBe(['errors' => [], 'warnings' => []]);
});

test('a missing start block is an error', function () {
    $definition = validScenarioDefinition();
    array_shift($definition['nodes']);

    expect(validateScenario($definition)['errors'])->toContain('В сценарии нет блока «Старт».');
});

test('a second start block is an error', function () {
    $definition = validScenarioDefinition();
    $definition['nodes'][] = ['id' => 'start2', 'type' => 'start'];
    $definition['edges'][] = ['from' => 'start2', 'output' => 'continue', 'to' => 'menu'];

    expect(validateScenario($definition)['errors'])->toContain('Блок «Старт» должен быть единственным.');
});

test('an unconnected start output blocks publication', function () {
    $definition = validScenarioDefinition();
    $definition['edges'] = array_slice($definition['edges'], 1);

    expect(validateScenario($definition)['errors'])->toContain('Выход блока «Старт» не подключен.');
});

test('a message block without text is an error', function () {
    $definition = validScenarioDefinition();
    $definition['nodes'][2]['text'] = '';

    expect(validateScenario($definition)['errors'])->toHaveCount(1)
        ->and(validateScenario($definition)['errors'][0])->toContain('не заполнен текст');
});

test('an interactive block without options is an error', function () {
    $definition = validScenarioDefinition();
    $definition['nodes'][1]['options'] = [];

    expect(validateScenario($definition)['errors'])->toHaveCount(1)
        ->and(validateScenario($definition)['errors'][0])->toContain('нет ни одного варианта');
});

test('more than three buttons violate the WhatsApp limit', function () {
    $definition = validScenarioDefinition();

    foreach (['a', 'b', 'c'] as $extra) {
        $definition['nodes'][1]['options'][] = ['id' => $extra, 'title' => 'Вариант '.$extra];
        $definition['edges'][] = ['from' => 'menu', 'output' => 'option:'.$extra, 'to' => 'supplier_branch'];
    }

    expect(validateScenario($definition)['errors'])->toHaveCount(1)
        ->and(validateScenario($definition)['errors'][0])->toContain('больше 3');
});

test('more than ten list rows violate the WhatsApp limit', function () {
    $options = [];
    $edges = [['from' => 'start', 'output' => 'continue', 'to' => 'list']];

    foreach (range(1, 11) as $i) {
        $options[] = ['id' => 'opt'.$i, 'title' => 'Вариант '.$i];
        $edges[] = ['from' => 'list', 'output' => 'option:opt'.$i, 'to' => 'end'];
    }

    $definition = [
        'nodes' => [
            ['id' => 'start', 'type' => 'start'],
            ['id' => 'list', 'type' => 'list', 'text' => 'Выберите', 'options' => $options],
            ['id' => 'end', 'type' => 'text', 'text' => 'Конец'],
        ],
        'edges' => $edges,
    ];

    expect(validateScenario($definition)['errors'])->toHaveCount(1)
        ->and(validateScenario($definition)['errors'][0])->toContain('больше 10');
});

test('a button title longer than twenty characters is an error', function () {
    $definition = validScenarioDefinition();
    $definition['nodes'][1]['options'][0]['title'] = str_repeat('а', 21);

    expect(validateScenario($definition)['errors'])->toHaveCount(1)
        ->and(validateScenario($definition)['errors'][0])->toContain('длиннее 20 символов');
});

test('an option without a title is an error', function () {
    $definition = validScenarioDefinition();
    $definition['nodes'][1]['options'][0]['title'] = '  ';

    expect(validateScenario($definition)['errors'])->toContain('У блока «Кто вы?» есть вариант без названия.');
});

test('an unconnected option output blocks publication', function () {
    $definition = validScenarioDefinition();
    array_pop($definition['edges']);

    expect(validateScenario($definition)['errors'])
        ->toContain('В блоке «Кто вы?» не подключен выход варианта «Заказчик».');
});

test('an edge to a missing block is an error', function () {
    $definition = validScenarioDefinition();
    $definition['edges'][] = ['from' => 'supplier_branch', 'output' => 'continue', 'to' => 'ghost'];

    expect(validateScenario($definition)['errors'])->toContain('Связь ссылается на несуществующий блок.');
});

test('an unknown block type is an error', function () {
    $definition = validScenarioDefinition();
    $definition['nodes'][] = ['id' => 'weird', 'type' => 'carousel'];

    expect(validateScenario($definition)['errors'])->toContain('Блок «weird» имеет неизвестный тип.');
});

test('a block unreachable from start is a warning, not an error', function () {
    $definition = validScenarioDefinition();
    $definition['nodes'][] = ['id' => 'orphan', 'type' => 'text', 'text' => 'Сирота'];

    $result = validateScenario($definition);

    expect($result['errors'])->toBe([])
        ->and($result['warnings'])->toContain('Блок «Сирота» недостижим от «Старта».');
});

test('an unconnected fallback output is allowed', function () {
    // «Любая другая фраза» может быть не подключена — бот повторит шаг.
    expect(validateScenario(validScenarioDefinition())['errors'])->toBe([]);
});

test('a skipped output on an action with a precondition is valid', function () {
    expect(validateScenario(validRunDefinition(), BotScenarioTrigger::NewCustomerRequest))
        ->toBe(['errors' => [], 'warnings' => []]);
});

test('an unconnected skipped output is allowed — the run just ends quietly', function () {
    $definition = validRunDefinition();
    $definition['edges'] = array_filter($definition['edges'], fn (array $edge): bool => $edge['output'] !== 'skipped');

    expect(validateScenario($definition, BotScenarioTrigger::NewCustomerRequest)['errors'])->toBe([]);
});

test('a skipped output on a best-effort action is an error', function () {
    $definition = validRunDefinition();
    $definition['nodes'][2]['action'] = 'notify_customer';

    expect(validateScenario($definition, BotScenarioTrigger::NewCustomerRequest)['errors'])
        ->toContain('У блока «do_accept» подключен выход «Не выполнено», но действие «Уведомить заказчика об исходе» не может остаться невыполненным.');
});

test('an action without a selected action is an error', function () {
    $definition = validRunDefinition();
    unset($definition['nodes'][2]['action']);

    expect(validateScenario($definition, BotScenarioTrigger::NewCustomerRequest)['errors'])
        ->toContain('У блока «do_accept» не выбрано действие.');
});

test('an action of another trigger is an error', function () {
    $definition = validRunDefinition();
    $definition['nodes'][2]['action'] = 'renew_listing';

    expect(validateScenario($definition, BotScenarioTrigger::NewCustomerRequest)['errors'])
        ->toContain('Действие «Продлить объявление на 30 дней» блока «do_accept» недоступно в сценарии с триггером «Новая заявка».');
});

test('a condition without connected yes/no outputs is an error', function () {
    $definition = validRunDefinition();
    $definition['nodes'][] = ['id' => 'check', 'type' => 'condition', 'condition' => 'request_pending'];
    $definition['edges'][] = ['from' => 'done', 'output' => 'continue', 'to' => 'check'];
    $definition['edges'][] = ['from' => 'check', 'output' => 'yes', 'to' => 'skipped_text'];

    expect(validateScenario($definition, BotScenarioTrigger::NewCustomerRequest)['errors'])
        ->toContain('В блоке «check» не подключен выход «Нет».');
});

test('a condition without a selected condition is an error', function () {
    $definition = validRunDefinition();
    $definition['nodes'][] = ['id' => 'check', 'type' => 'condition'];
    $definition['edges'][] = ['from' => 'done', 'output' => 'continue', 'to' => 'check'];
    $definition['edges'][] = ['from' => 'check', 'output' => 'yes', 'to' => 'skipped_text'];
    $definition['edges'][] = ['from' => 'check', 'output' => 'no', 'to' => 'skipped_text'];

    expect(validateScenario($definition, BotScenarioTrigger::NewCustomerRequest)['errors'])
        ->toContain('У блока «check» не выбрано условие.');
});

test('a condition of another trigger is an error', function () {
    $definition = validRunDefinition();
    $definition['nodes'][] = ['id' => 'check', 'type' => 'condition', 'condition' => 'listing_published'];
    $definition['edges'][] = ['from' => 'done', 'output' => 'continue', 'to' => 'check'];
    $definition['edges'][] = ['from' => 'check', 'output' => 'yes', 'to' => 'skipped_text'];
    $definition['edges'][] = ['from' => 'check', 'output' => 'no', 'to' => 'skipped_text'];

    expect(validateScenario($definition, BotScenarioTrigger::NewCustomerRequest)['errors'])
        ->toContain('Условие «Объявление опубликовано» блока «check» недоступно в сценарии с триггером «Новая заявка».');
});

test('validateDetailed attaches the block id to each issue', function () {
    $definition = validRunDefinition();
    $definition['nodes'][2]['action'] = 'notify_customer';
    $definition['nodes'][] = ['id' => 'orphan', 'type' => 'text', 'text' => 'Сирота'];

    $result = (new ScenarioValidator)->validateDetailed($definition, BotScenarioTrigger::NewCustomerRequest);

    expect(collect($result['errors'])->firstWhere('node_id', 'do_accept')['message'])
        ->toContain('не может остаться невыполненным')
        ->and(collect($result['warnings'])->firstWhere('node_id', 'orphan')['message'])
        ->toContain('недостижим от «Старта»');
});
