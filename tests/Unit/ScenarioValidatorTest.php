<?php

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

function validateScenario(array $definition): array
{
    return (new ScenarioValidator)->validate($definition);
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
