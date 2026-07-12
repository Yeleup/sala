<?php

use App\Enums\AiOutcome;
use App\Models\BotScenario;
use App\Models\BotSession;
use App\Models\Contact;
use App\Services\Bot\AiAssistant;
use App\Services\Bot\BotEngine;
use App\Services\Bot\InboundMessage;
use App\Services\Bot\PassthroughAiAssistant;
use App\Services\DereuMessenger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;

uses(RefreshDatabase::class);

/**
 * Старт → приветствие (текст) → меню ролей (кнопки) с двумя ветками;
 * выход «Любая другая фраза» меню не подключен.
 */
function botMenuDefinition(): array
{
    return [
        'nodes' => [
            ['id' => 'start', 'type' => 'start'],
            ['id' => 'greeting', 'type' => 'text', 'text' => 'Привет!'],
            ['id' => 'menu', 'type' => 'buttons', 'text' => 'Кто вы?', 'options' => [
                ['id' => 'supplier', 'title' => 'Поставщик'],
                ['id' => 'customer', 'title' => 'Заказчик'],
            ]],
            ['id' => 'supplier_branch', 'type' => 'text', 'text' => 'Ветка поставщика'],
            ['id' => 'customer_branch', 'type' => 'text', 'text' => 'Ветка заказчика'],
            ['id' => 'fallback_hint', 'type' => 'text', 'text' => 'Не понял вас'],
        ],
        'edges' => [
            ['from' => 'start', 'output' => 'continue', 'to' => 'greeting'],
            ['from' => 'greeting', 'output' => 'continue', 'to' => 'menu'],
            ['from' => 'menu', 'output' => 'option:supplier', 'to' => 'supplier_branch'],
            ['from' => 'menu', 'output' => 'option:customer', 'to' => 'customer_branch'],
        ],
    ];
}

function botSessionWaitingAt(BotScenario $scenario, Contact $contact, string $nodeId): BotSession
{
    return BotSession::factory()->waitingAt($nodeId)->create([
        'contact_id' => $contact->id,
        'bot_scenario_id' => $scenario->id,
        'scenario_version' => $scenario->published_version,
    ]);
}

function fakeBotMessenger(): MockInterface
{
    return test()->mock(DereuMessenger::class);
}

test('the first inbound message runs the flow from start to the first waiting block', function () {
    BotScenario::factory()->published(botMenuDefinition())->create();
    $contact = Contact::factory()->create();

    $messenger = fakeBotMessenger();
    $messenger->shouldReceive('sendText')->once()
        ->withArgs(fn (Contact $to, string $text) => $to->is($contact) && $text === 'Привет!');
    $messenger->shouldReceive('sendButtons')->once()
        ->withArgs(fn (Contact $to, string $text, array $buttons) => $text === 'Кто вы?'
            && array_column($buttons, 'title') === ['Поставщик', 'Заказчик']);

    app(BotEngine::class)->handle($contact, new InboundMessage(text: 'Привет'));

    expect(BotSession::sole())
        ->contact_id->toBe($contact->id)
        ->current_node_id->toBe('menu');
});

test('nothing is sent while no scenario is published', function () {
    BotScenario::factory()->create();
    $contact = Contact::factory()->create();

    fakeBotMessenger()->shouldNotReceive('sendText', 'sendButtons', 'sendList');

    app(BotEngine::class)->handle($contact, new InboundMessage(text: 'Привет'));

    expect(BotSession::count())->toBe(0);
});

test('pressing a button moves the contact along that output', function () {
    $scenario = BotScenario::factory()->published(botMenuDefinition())->create();
    $contact = Contact::factory()->create();
    $session = botSessionWaitingAt($scenario, $contact, 'menu');

    fakeBotMessenger()->shouldReceive('sendText')->once()
        ->withArgs(fn (Contact $to, string $text) => $text === 'Ветка поставщика');

    app(BotEngine::class)->handle($contact, new InboundMessage(text: 'Поставщик', replyId: 'supplier'));

    expect($session->fresh()->current_node_id)->toBeNull();
});

test('a text reply equal to an option title counts as picking that option', function () {
    $scenario = BotScenario::factory()->published(botMenuDefinition())->create();
    $contact = Contact::factory()->create();
    botSessionWaitingAt($scenario, $contact, 'menu');

    fakeBotMessenger()->shouldReceive('sendText')->once()
        ->withArgs(fn (Contact $to, string $text) => $text === 'Ветка заказчика');

    app(BotEngine::class)->handle($contact, new InboundMessage(text: '  ЗАКАЗЧИК '));
});

test('an unrecognized reply follows the connected fallback output', function () {
    $definition = botMenuDefinition();
    $definition['edges'][] = ['from' => 'menu', 'output' => 'fallback', 'to' => 'fallback_hint'];
    $scenario = BotScenario::factory()->published($definition)->create();
    $contact = Contact::factory()->create();
    botSessionWaitingAt($scenario, $contact, 'menu');

    fakeBotMessenger()->shouldReceive('sendText')->once()
        ->withArgs(fn (Contact $to, string $text) => $text === 'Не понял вас');

    app(BotEngine::class)->handle($contact, new InboundMessage(text: 'что-то невнятное'));
});

test('an unrecognized reply without a fallback repeats the current step', function () {
    $scenario = BotScenario::factory()->published(botMenuDefinition())->create();
    $contact = Contact::factory()->create();
    $session = botSessionWaitingAt($scenario, $contact, 'menu');

    fakeBotMessenger()->shouldReceive('sendButtons')->once()
        ->withArgs(fn (Contact $to, string $text) => $text === 'Кто вы?');

    app(BotEngine::class)->handle($contact, new InboundMessage(text: 'что-то невнятное'));

    expect($session->fresh()->current_node_id)->toBe('menu');
});

test('picking a list row moves the contact along that output', function () {
    $definition = [
        'nodes' => [
            ['id' => 'start', 'type' => 'start'],
            ['id' => 'categories', 'type' => 'list', 'text' => 'Выберите категорию', 'button' => 'Категории', 'options' => [
                ['id' => 'crane', 'title' => 'Кран'],
                ['id' => 'excavator', 'title' => 'Экскаватор'],
                ['id' => 'welder', 'title' => 'Сварщик'],
                ['id' => 'other', 'title' => 'Другое'],
            ]],
            ['id' => 'crane_branch', 'type' => 'text', 'text' => 'Вы выбрали кран'],
        ],
        'edges' => [
            ['from' => 'start', 'output' => 'continue', 'to' => 'categories'],
            ['from' => 'categories', 'output' => 'option:crane', 'to' => 'crane_branch'],
        ],
    ];
    $scenario = BotScenario::factory()->published($definition)->create();
    $contact = Contact::factory()->create();
    botSessionWaitingAt($scenario, $contact, 'categories');

    fakeBotMessenger()->shouldReceive('sendText')->once()
        ->withArgs(fn (Contact $to, string $text) => $text === 'Вы выбрали кран');

    app(BotEngine::class)->handle($contact, new InboundMessage(text: 'Кран', replyId: 'crane'));
});

test('after a finished dialog the next message starts a new dialog from start', function () {
    $scenario = BotScenario::factory()->published(botMenuDefinition())->create();
    $contact = Contact::factory()->create();
    BotSession::factory()->create([
        'contact_id' => $contact->id,
        'bot_scenario_id' => $scenario->id,
        'scenario_version' => 1,
        'current_node_id' => null,
    ]);

    $messenger = fakeBotMessenger();
    $messenger->shouldReceive('sendText')->once();
    $messenger->shouldReceive('sendButtons')->once();

    app(BotEngine::class)->handle($contact, new InboundMessage(text: 'Привет'));

    expect(BotSession::sole()->current_node_id)->toBe('menu');
});

test('after 24 hours of silence the dialog restarts from start', function () {
    $scenario = BotScenario::factory()->published(botMenuDefinition())->create();
    $contact = Contact::factory()->create();
    BotSession::factory()->waitingAt('menu')->expired()->create([
        'contact_id' => $contact->id,
        'bot_scenario_id' => $scenario->id,
        'scenario_version' => 1,
    ]);

    $messenger = fakeBotMessenger();
    $messenger->shouldReceive('sendText')->once()
        ->withArgs(fn (Contact $to, string $text) => $text === 'Привет!');
    $messenger->shouldReceive('sendButtons')->once();

    // «Поставщик» больше не ответ на меню — это начало нового диалога.
    app(BotEngine::class)->handle($contact, new InboundMessage(text: 'Поставщик'));

    expect(BotSession::sole()->current_node_id)->toBe('menu');
});

test('republication that removed the awaited block softly resets the dialog to start', function () {
    $scenario = BotScenario::factory()->published(botMenuDefinition())->create();
    $contact = Contact::factory()->create();
    botSessionWaitingAt($scenario, $contact, 'menu');

    $scenario->update([
        'published_definition' => [
            'nodes' => [
                ['id' => 'start', 'type' => 'start'],
                ['id' => 'intro', 'type' => 'text', 'text' => 'Новая версия'],
                ['id' => 'menu_v2', 'type' => 'buttons', 'text' => 'Выберите роль', 'options' => [
                    ['id' => 'supplier', 'title' => 'Поставщик'],
                ]],
            ],
            'edges' => [
                ['from' => 'start', 'output' => 'continue', 'to' => 'intro'],
                ['from' => 'intro', 'output' => 'continue', 'to' => 'menu_v2'],
            ],
        ],
        'published_version' => 2,
    ]);

    $messenger = fakeBotMessenger();
    $messenger->shouldReceive('sendText')->once()
        ->withArgs(fn (Contact $to, string $text) => $text === 'Новая версия');
    $messenger->shouldReceive('sendButtons')->once();

    app(BotEngine::class)->handle($contact, new InboundMessage(text: 'Поставщик'));

    expect(BotSession::sole())
        ->current_node_id->toBe('menu_v2')
        ->scenario_version->toBe(2);
});

test('republication that kept the awaited block continues the dialog on the new version', function () {
    $scenario = BotScenario::factory()->published(botMenuDefinition())->create();
    $contact = Contact::factory()->create();
    botSessionWaitingAt($scenario, $contact, 'menu');

    $definition = botMenuDefinition();
    $definition['nodes'][4]['text'] = 'Обновлённая ветка заказчика';
    $scenario->update(['published_definition' => $definition, 'published_version' => 2]);

    fakeBotMessenger()->shouldReceive('sendText')->once()
        ->withArgs(fn (Contact $to, string $text) => $text === 'Обновлённая ветка заказчика');

    app(BotEngine::class)->handle($contact, new InboundMessage(text: 'Заказчик', replyId: 'customer'));

    expect(BotSession::sole()->scenario_version)->toBe(2);
});

test('republication that changed the options of the awaited block softly resets the dialog', function () {
    $scenario = BotScenario::factory()->published(botMenuDefinition())->create();
    $contact = Contact::factory()->create();
    $session = botSessionWaitingAt($scenario, $contact, 'menu');
    $session->update([
        'current_node_fingerprint' => (new App\Services\Bot\ScenarioDefinition(botMenuDefinition()))
            ->nodeFingerprint(botMenuDefinition()['nodes'][2]),
    ]);

    $definition = botMenuDefinition();
    $definition['nodes'][2]['options'][1]['title'] = 'Ищу технику';
    $scenario->update(['published_definition' => $definition, 'published_version' => 2]);

    $messenger = fakeBotMessenger();
    $messenger->shouldReceive('sendText')->once()
        ->withArgs(fn (Contact $to, string $text) => $text === 'Привет!');
    $messenger->shouldReceive('sendButtons')->once();

    // Ответ по старой кнопке «Заказчик» не проваливается в новую ветку —
    // диалог мягко начинается со «Старта».
    app(BotEngine::class)->handle($contact, new InboundMessage(text: 'Заказчик', replyId: 'customer'));

    expect(BotSession::sole())
        ->current_node_id->toBe('menu')
        ->scenario_version->toBe(2);
});

test('republication with only a text tweak keeps the fingerprinted step alive', function () {
    $scenario = BotScenario::factory()->published(botMenuDefinition())->create();
    $contact = Contact::factory()->create();
    $session = botSessionWaitingAt($scenario, $contact, 'menu');
    $session->update([
        'current_node_fingerprint' => (new App\Services\Bot\ScenarioDefinition(botMenuDefinition()))
            ->nodeFingerprint(botMenuDefinition()['nodes'][2]),
    ]);

    $definition = botMenuDefinition();
    $definition['nodes'][2]['text'] = 'Кем вы пользуетесь сервисом?';
    $scenario->update(['published_definition' => $definition, 'published_version' => 2]);

    fakeBotMessenger()->shouldReceive('sendText')->once()
        ->withArgs(fn (Contact $to, string $text) => $text === 'Ветка заказчика');

    app(BotEngine::class)->handle($contact, new InboundMessage(text: 'Заказчик', replyId: 'customer'));

    expect(BotSession::sole()->scenario_version)->toBe(2);
});

test('the my_listings block sends the personal portal link and ends the branch', function () {
    $scenario = BotScenario::factory()->published([
        'nodes' => [
            ['id' => 'start', 'type' => 'start'],
            ['id' => 'cabinet', 'type' => 'my_listings', 'text' => 'Откройте кабинет.'],
        ],
        'edges' => [
            ['from' => 'start', 'output' => 'continue', 'to' => 'cabinet'],
        ],
    ])->create();
    $contact = Contact::factory()->create();

    fakeBotMessenger()->shouldReceive('sendCtaUrl')->once()
        ->withArgs(fn (Contact $to, string $text, string $button, string $url) => $to->is($contact)
            && $text === 'Откройте кабинет.'
            && mb_strlen($button) <= 20
            && str_contains($url, "/supplier/{$contact->id}/listings")
            && str_contains($url, 'signature='));

    app(BotEngine::class)->handle($contact, new InboundMessage(text: 'Мои объявления'));

    expect(BotSession::sole()->current_node_id)->toBeNull();
});

test('an AI block with the placeholder assistant falls through its continue output', function () {
    app()->bind(AiAssistant::class, PassthroughAiAssistant::class);

    $scenario = BotScenario::factory()->published([
        'nodes' => [
            ['id' => 'start', 'type' => 'start'],
            ['id' => 'collect', 'type' => 'ai'],
            ['id' => 'done', 'type' => 'text', 'text' => 'Готово'],
        ],
        'edges' => [
            ['from' => 'start', 'output' => 'continue', 'to' => 'collect'],
            ['from' => 'collect', 'output' => 'continue', 'to' => 'done'],
        ],
    ])->create();
    $contact = Contact::factory()->create();

    fakeBotMessenger()->shouldReceive('sendText')->once()
        ->withArgs(fn (Contact $to, string $text) => $text === 'Готово');

    app(BotEngine::class)->handle($contact, new InboundMessage(text: 'Привет'));

    expect(BotSession::sole()->current_node_id)->toBeNull();
});

test('while the AI keeps the turn the contact waits at the AI block, and completion releases it', function () {
    BotScenario::factory()->published([
        'nodes' => [
            ['id' => 'start', 'type' => 'start'],
            ['id' => 'collect', 'type' => 'ai'],
            ['id' => 'done', 'type' => 'text', 'text' => 'Готово'],
        ],
        'edges' => [
            ['from' => 'start', 'output' => 'continue', 'to' => 'collect'],
            ['from' => 'collect', 'output' => 'continue', 'to' => 'done'],
        ],
    ])->create();
    $contact = Contact::factory()->create();

    $assistant = test()->mock(AiAssistant::class);
    $assistant->shouldReceive('start')->once()->andReturn(AiOutcome::InProgress);
    $assistant->shouldReceive('resume')->once()
        ->withArgs(fn (BotSession $session, array $node, InboundMessage $message) => $message->text === 'Кран 25 тонн')
        ->andReturn(AiOutcome::Completed);

    $messenger = fakeBotMessenger();
    $messenger->shouldReceive('sendText')->once()
        ->withArgs(fn (Contact $to, string $text) => $text === 'Готово');

    app(BotEngine::class)->handle($contact, new InboundMessage(text: 'Привет'));

    expect(BotSession::sole()->current_node_id)->toBe('collect');

    app(BotEngine::class)->handle($contact, new InboundMessage(text: 'Кран 25 тонн'));

    expect(BotSession::sole()->current_node_id)->toBeNull();
});

test('a cycle of auto-advancing blocks is capped and the dialog is parked', function () {
    BotScenario::factory()->published([
        'nodes' => [
            ['id' => 'start', 'type' => 'start'],
            ['id' => 'a', 'type' => 'text', 'text' => 'А'],
            ['id' => 'b', 'type' => 'text', 'text' => 'Б'],
        ],
        'edges' => [
            ['from' => 'start', 'output' => 'continue', 'to' => 'a'],
            ['from' => 'a', 'output' => 'continue', 'to' => 'b'],
            ['from' => 'b', 'output' => 'continue', 'to' => 'a'],
        ],
    ])->create();
    $contact = Contact::factory()->create();

    fakeBotMessenger()->shouldReceive('sendText')->atMost()->times(20);

    app(BotEngine::class)->handle($contact, new InboundMessage(text: 'Привет'));

    expect(BotSession::sole()->current_node_id)->toBeNull();
});
