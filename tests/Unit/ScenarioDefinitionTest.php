<?php

use App\Services\Bot\ScenarioDefinition;

/**
 * Главное меню → экран раздела → AI-блок, как в типовом сценарии; выход
 * «Продолжить» AI-блока ведёт обратно в главное меню.
 */
function twoLevelMenuDefinition(): array
{
    return [
        'nodes' => [
            ['id' => 'start', 'type' => 'start'],
            ['id' => 'main_menu', 'type' => 'buttons', 'text' => 'Что вас интересует?', 'options' => [
                ['id' => 'kind_repair', 'title' => 'Ремонт спецтехники'],
            ]],
            ['id' => 'menu_repair', 'type' => 'buttons', 'text' => 'Вы мастер или ищете мастера?', 'options' => [
                ['id' => 'master', 'title' => 'Я мастер'],
                ['id' => 'master_seek', 'title' => 'Я ищу мастера'],
            ]],
            ['id' => 'collect_repair', 'type' => 'ai', 'task' => 'collect_listing'],
            ['id' => 'search_intro', 'type' => 'text', 'text' => 'Сейчас поищем.'],
            ['id' => 'search_repair', 'type' => 'ai', 'task' => 'customer_search'],
            ['id' => 'orphan', 'type' => 'ai', 'task' => 'customer_search'],
        ],
        'edges' => [
            ['from' => 'start', 'output' => 'continue', 'to' => 'main_menu'],
            ['from' => 'main_menu', 'output' => 'option:kind_repair', 'to' => 'menu_repair'],
            ['from' => 'menu_repair', 'output' => 'option:master', 'to' => 'collect_repair'],
            ['from' => 'menu_repair', 'output' => 'option:master_seek', 'to' => 'search_intro'],
            ['from' => 'search_intro', 'output' => 'continue', 'to' => 'search_repair'],
            ['from' => 'collect_repair', 'output' => 'continue', 'to' => 'main_menu'],
            ['from' => 'search_repair', 'output' => 'continue', 'to' => 'main_menu'],
        ],
    ];
}

test('the parent menu of an AI block is the menu whose option leads into it', function () {
    $definition = new ScenarioDefinition(twoLevelMenuDefinition());

    expect($definition->parentMenuOf('collect_repair'))->toBe('menu_repair')
        ->and($definition->parentMenuOf('menu_repair'))->toBe('main_menu');
});

test('the parent menu is found through blocks that wait for nothing on the way', function () {
    $definition = new ScenarioDefinition(twoLevelMenuDefinition());

    expect($definition->parentMenuOf('search_repair'))->toBe('menu_repair');
});

test('a block no menu option leads into has no parent menu', function () {
    $definition = new ScenarioDefinition(twoLevelMenuDefinition());

    expect($definition->parentMenuOf('orphan'))->toBeNull()
        ->and($definition->parentMenuOf('main_menu'))->toBeNull()
        ->and($definition->parentMenuOf('missing'))->toBeNull();
});

test('when two menus lead into the same block the first in graph order wins', function () {
    $definition = twoLevelMenuDefinition();
    $definition['nodes'][] = ['id' => 'shortcut', 'type' => 'buttons', 'text' => 'Быстрый вход', 'options' => [
        ['id' => 'shortcut_master', 'title' => 'Сразу к мастеру'],
    ]];
    $definition['edges'][] = ['from' => 'shortcut', 'output' => 'option:shortcut_master', 'to' => 'collect_repair'];

    expect((new ScenarioDefinition($definition))->parentMenuOf('collect_repair'))->toBe('menu_repair');
});

test('an interactive list menu is a parent like a buttons menu', function () {
    $definition = twoLevelMenuDefinition();
    $definition['nodes'][] = ['id' => 'list_menu', 'type' => 'list', 'text' => 'Выберите', 'options' => [
        ['id' => 'list_orphan', 'title' => 'К поиску'],
    ]];
    $definition['edges'][] = ['from' => 'list_menu', 'output' => 'option:list_orphan', 'to' => 'orphan'];

    expect((new ScenarioDefinition($definition))->parentMenuOf('orphan'))->toBe('list_menu');
});
