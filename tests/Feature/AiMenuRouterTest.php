<?php

use App\Ai\Agents\MenuRouteAgent;
use App\Enums\AiOperationStatus;
use App\Enums\AiOperationType;
use App\Enums\MenuRouteKind;
use App\Enums\RouteConfidence;
use App\Models\AiOperation;
use App\Models\BotSession;
use App\Services\Ai\AiMenuRouter;
use App\Services\Bot\InboundMessage;
use App\Services\Bot\MenuRoute;
use App\Services\Bot\ScenarioDefinition;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Верхнее меню (кнопки, «Поставщик»/«Заказчик») → список поставщика (list,
 * «Сдать в аренду»/«Ремонт техники») → два ai-узла анкет. «Сдать в аренду»
 * ведёт ровно в 'collect' — узел, который тесты снапшота прерванной анкеты
 * используют как цель резюме, что даёт сценарий и для пункта 8 (опция,
 * ведущая в узел снапшота).
 */
function menuRouterDefinition(): array
{
    return [
        'nodes' => [
            ['id' => 'start', 'type' => 'start'],
            ['id' => 'menu', 'type' => 'buttons', 'text' => 'Кто вы?', 'options' => [
                ['id' => 'supplier', 'title' => 'Поставщик'],
                ['id' => 'customer', 'title' => 'Заказчик'],
            ]],
            ['id' => 'supplier_menu', 'type' => 'list', 'text' => 'Что предлагаете?', 'button' => 'Выбрать', 'options' => [
                ['id' => 'rent_out', 'title' => 'Сдать в аренду'],
                ['id' => 'repair', 'title' => 'Ремонт техники'],
            ]],
            ['id' => 'collect', 'type' => 'ai', 'task' => 'collect_listing'],
            ['id' => 'collect_repair', 'type' => 'ai', 'task' => 'collect_listing', 'kind' => 'repair'],
            ['id' => 'customer_branch', 'type' => 'text', 'text' => 'Ветка заказчика'],
        ],
        'edges' => [
            ['from' => 'start', 'output' => 'continue', 'to' => 'menu'],
            ['from' => 'menu', 'output' => 'option:supplier', 'to' => 'supplier_menu'],
            ['from' => 'menu', 'output' => 'option:customer', 'to' => 'customer_branch'],
            ['from' => 'supplier_menu', 'output' => 'option:rent_out', 'to' => 'collect'],
            ['from' => 'supplier_menu', 'output' => 'option:repair', 'to' => 'collect_repair'],
        ],
    ];
}

/**
 * @return array<string, mixed>
 */
function menuRouterMenuNode(): array
{
    return menuRouterDefinition()['nodes'][1];
}

/**
 * A valid, fresh paused-questionnaire snapshot pointed at 'collect' — the
 * shape BotSession::pausedState() expects. Overrides let a test break
 * exactly one field to prove the router refuses to offer resume.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function pausedListingSnapshot(array $overrides = []): array
{
    $definition = new ScenarioDefinition(menuRouterDefinition());

    return array_merge([
        'node_id' => 'collect',
        'fingerprint' => $definition->nodeFingerprint($definition->node('collect')),
        'state' => ['kind' => 'rental'],
        'saved_at' => now()->toIso8601String(),
    ], $overrides);
}

function menuRouterSession(?array $pausedState = null): BotSession
{
    return BotSession::factory()->create(['paused_state' => $pausedState]);
}

describe('гварды без обращения к модели', function () {
    test('пустое сообщение не доходит до модели', function () {
        MenuRouteAgent::fake()->preventStrayPrompts();
        $session = menuRouterSession();

        $route = app(AiMenuRouter::class)->route(
            $session,
            new ScenarioDefinition(menuRouterDefinition()),
            menuRouterMenuNode(),
            new InboundMessage(text: '   '),
        );

        expect($route)->toBeNull();
        MenuRouteAgent::assertNeverPrompted();
    });

    test('одинокое число не доходит до модели — это промахнувшийся порядковый выбор, а не интент', function () {
        MenuRouteAgent::fake()->preventStrayPrompts();
        $session = menuRouterSession();

        $route = app(AiMenuRouter::class)->route(
            $session,
            new ScenarioDefinition(menuRouterDefinition()),
            menuRouterMenuNode(),
            new InboundMessage(text: '7'),
        );

        expect($route)->toBeNull();
        MenuRouteAgent::assertNeverPrompted();
    });
});

describe('маппинг ответа модели', function () {
    test('выбранная опция маршрутизирует к ней с уверенностью модели', function (string $confidenceValue, RouteConfidence $expected) {
        $session = menuRouterSession();
        MenuRouteAgent::fake([['route' => 'option:customer', 'confidence' => $confidenceValue]]);

        $route = app(AiMenuRouter::class)->route(
            $session,
            new ScenarioDefinition(menuRouterDefinition()),
            menuRouterMenuNode(),
            new InboundMessage(text: 'хочу найти технику для стройки'),
        );

        expect($route)->toBeInstanceOf(MenuRoute::class)
            ->and($route->kind)->toBe(MenuRouteKind::Option)
            ->and($route->option)->toBe(['node_id' => 'menu', 'option_id' => 'customer'])
            ->and($route->confidence)->toBe($expected);
    })->with([
        'высокая уверенность' => ['high', RouteConfidence::High],
        'средняя уверенность' => ['medium', RouteConfidence::Medium],
    ]);

    test('низкая уверенность всегда даёт null, независимо от выбранного маршрута', function () {
        $session = menuRouterSession();
        MenuRouteAgent::fake([['route' => 'option:customer', 'confidence' => 'low']]);

        $route = app(AiMenuRouter::class)->route(
            $session,
            new ScenarioDefinition(menuRouterDefinition()),
            menuRouterMenuNode(),
            new InboundMessage(text: 'что-то невнятное про технику'),
        );

        expect($route)->toBeNull();
    });

    test('route=none всегда даёт null, при любой уверенности', function (string $confidenceValue) {
        $session = menuRouterSession();
        MenuRouteAgent::fake([['route' => 'none', 'confidence' => $confidenceValue]]);

        $route = app(AiMenuRouter::class)->route(
            $session,
            new ScenarioDefinition(menuRouterDefinition()),
            menuRouterMenuNode(),
            new InboundMessage(text: 'привет, как дела?'),
        );

        expect($route)->toBeNull();
    })->with(['high', 'medium', 'low']);

    test('вопрос о сервисе маршрутизирует в ServiceQuestion', function () {
        $session = menuRouterSession();
        MenuRouteAgent::fake([['route' => 'service_question', 'confidence' => 'high']]);

        $route = app(AiMenuRouter::class)->route(
            $session,
            new ScenarioDefinition(menuRouterDefinition()),
            menuRouterMenuNode(),
            new InboundMessage(text: 'а сколько стоит размещение объявления?'),
        );

        expect($route)->toBeInstanceOf(MenuRoute::class)
            ->and($route->kind)->toBe(MenuRouteKind::ServiceQuestion)
            ->and($route->option)->toBeNull()
            ->and($route->confidence)->toBe(RouteConfidence::High);
    });
});

describe('аудит вызова', function () {
    test('исключение агента даёт null и строку ai_operations со статусом сбоя', function () {
        $session = menuRouterSession();
        MenuRouteAgent::fake(fn () => throw new RuntimeException('провайдер недоступен'));

        $route = app(AiMenuRouter::class)->route(
            $session,
            new ScenarioDefinition(menuRouterDefinition()),
            menuRouterMenuNode(),
            new InboundMessage(text: 'что-то невнятное про технику'),
        );

        expect($route)->toBeNull();
        expect(AiOperation::sole())
            ->operation->toBe(AiOperationType::MenuRouting)
            ->status->toBe(AiOperationStatus::Failed);
    });

    test('успешный вызов оставляет завершённую строку ai_operations с контактом и сессией', function () {
        $session = menuRouterSession();
        MenuRouteAgent::fake([['route' => 'service_question', 'confidence' => 'high']]);

        app(AiMenuRouter::class)->route(
            $session,
            new ScenarioDefinition(menuRouterDefinition()),
            menuRouterMenuNode(),
            new InboundMessage(text: 'а как вообще работает этот бот?'),
        );

        expect(AiOperation::sole())
            ->operation->toBe(AiOperationType::MenuRouting)
            ->status->toBe(AiOperationStatus::Completed)
            ->contact_id->toBe($session->contact_id)
            ->bot_session_id->toBe($session->id);
    });
});

describe('резюме прерванной анкеты', function () {
    test('resume не предлагается без свежего совпадающего снапшота — route=resume от фейка маппится в null', function (?array $pausedState) {
        $session = menuRouterSession($pausedState);
        MenuRouteAgent::fake([['route' => 'resume', 'confidence' => 'high']]);

        $route = app(AiMenuRouter::class)->route(
            $session,
            new ScenarioDefinition(menuRouterDefinition()),
            menuRouterMenuNode(),
            new InboundMessage(text: 'продолжим то, что начали'),
        );

        expect($route)->toBeNull();
    })->with([
        'снапшота нет' => [null],
        'снапшот протух (>48ч)' => [pausedListingSnapshot(['saved_at' => '2020-01-01T00:00:00+00:00'])],
        'fingerprint снапшота не совпадает с текущим' => [pausedListingSnapshot(['fingerprint' => 'stale-fingerprint'])],
        'узел снапшота не ai' => [pausedListingSnapshot([
            'node_id' => 'menu',
            'fingerprint' => (new ScenarioDefinition(menuRouterDefinition()))->nodeFingerprint(menuRouterDefinition()['nodes'][1]),
        ])],
        'узел снапшота отсутствует в графе' => [pausedListingSnapshot(['node_id' => 'ghost', 'fingerprint' => 'irrelevant'])],
    ]);

    test('валидный снапшот плюс route=resume даёт маршрут Resume', function () {
        $session = menuRouterSession(pausedListingSnapshot());
        MenuRouteAgent::fake([['route' => 'resume', 'confidence' => 'medium']]);

        $route = app(AiMenuRouter::class)->route(
            $session,
            new ScenarioDefinition(menuRouterDefinition()),
            menuRouterMenuNode(),
            new InboundMessage(text: 'вернёмся к тому, что я заполнял'),
        );

        expect($route)->toBeInstanceOf(MenuRoute::class)
            ->and($route->kind)->toBe(MenuRouteKind::Resume)
            ->and($route->option)->toBeNull()
            ->and($route->confidence)->toBe(RouteConfidence::Medium);
    });

    test('валидный снапшот плюс опция, ведущая ровно в узел снапшота, тоже даёт Resume — постобработка кодом', function () {
        $session = menuRouterSession(pausedListingSnapshot());
        MenuRouteAgent::fake([['route' => 'option:rent_out', 'confidence' => 'high']]);

        $route = app(AiMenuRouter::class)->route(
            $session,
            new ScenarioDefinition(menuRouterDefinition()),
            menuRouterMenuNode(),
            new InboundMessage(text: 'я же говорил — хочу сдать трактор в аренду'),
        );

        expect($route)->toBeInstanceOf(MenuRoute::class)
            ->and($route->kind)->toBe(MenuRouteKind::Resume)
            ->and($route->option)->toBeNull()
            ->and($route->confidence)->toBe(RouteConfidence::High);
    });

    test('валидный снапшот не трогает опцию, ведущую не в узел снапшота — остаётся Option', function () {
        $session = menuRouterSession(pausedListingSnapshot());
        MenuRouteAgent::fake([['route' => 'option:customer', 'confidence' => 'high']]);

        $route = app(AiMenuRouter::class)->route(
            $session,
            new ScenarioDefinition(menuRouterDefinition()),
            menuRouterMenuNode(),
            new InboundMessage(text: 'на самом деле я заказчик'),
        );

        expect($route)->toBeInstanceOf(MenuRoute::class)
            ->and($route->kind)->toBe(MenuRouteKind::Option)
            ->and($route->option)->toBe(['node_id' => 'menu', 'option_id' => 'customer']);
    });
});

describe('ScenarioDefinition::menuOptions()', function () {
    test('собирает все опции всех узлов buttons/list графа, с node_id/title/context, и пропускает остальные узлы', function () {
        $definition = new ScenarioDefinition(menuRouterDefinition());

        expect($definition->menuOptions())->toBe([
            'supplier' => ['node_id' => 'menu', 'option_id' => 'supplier', 'title' => 'Поставщик', 'context' => 'Кто вы?'],
            'customer' => ['node_id' => 'menu', 'option_id' => 'customer', 'title' => 'Заказчик', 'context' => 'Кто вы?'],
            'rent_out' => ['node_id' => 'supplier_menu', 'option_id' => 'rent_out', 'title' => 'Сдать в аренду', 'context' => 'Что предлагаете?'],
            'repair' => ['node_id' => 'supplier_menu', 'option_id' => 'repair', 'title' => 'Ремонт техники', 'context' => 'Что предлагаете?'],
        ]);
    });
});
