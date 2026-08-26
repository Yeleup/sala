<?php

use App\Enums\AiOutcome;
use App\Enums\ListingMediaType;
use App\Enums\RouteConfidence;
use App\Models\BotScenario;
use App\Models\BotSession;
use App\Models\Contact;
use App\Services\Ai\VoiceTranscriber;
use App\Services\Bot\AiAssistant;
use App\Services\Bot\BotEngine;
use App\Services\Bot\InboundMessage;
use App\Services\Bot\MenuRoute;
use App\Services\Bot\MenuRouter;
use App\Services\Bot\ScenarioDefinition;
use App\Services\DereuMediaDownloader;
use App\Services\DereuMessenger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Mockery\MockInterface;

uses(RefreshDatabase::class);

/**
 * Старт → приветствие → меню ролей: «Я поставщик» уводит в анкету (AI),
 * «Я заказчик» — в обычный текстовый блок. Выход «Любая другая фраза» не
 * подключён, поэтому нераспознанный текст доходит до ИИ-навигатора.
 */
function navMenuDefinition(): array
{
    return [
        'nodes' => [
            ['id' => 'start', 'type' => 'start'],
            ['id' => 'greeting', 'type' => 'text', 'text' => 'Привет!'],
            ['id' => 'menu', 'type' => 'buttons', 'text' => 'Кто вы?', 'options' => [
                ['id' => 'supplier', 'title' => 'Я поставщик'],
                ['id' => 'customer', 'title' => 'Я заказчик'],
            ]],
            ['id' => 'collect', 'type' => 'ai', 'task' => 'collect_listing'],
            ['id' => 'customer_branch', 'type' => 'text', 'text' => 'Ветка заказчика'],
            ['id' => 'after_collect', 'type' => 'text', 'text' => 'Анкета закрыта'],
            ['id' => 'fallback_hint', 'type' => 'text', 'text' => 'Не понял вас'],
        ],
        'edges' => [
            ['from' => 'start', 'output' => 'continue', 'to' => 'greeting'],
            ['from' => 'greeting', 'output' => 'continue', 'to' => 'menu'],
            ['from' => 'menu', 'output' => 'option:supplier', 'to' => 'collect'],
            ['from' => 'menu', 'output' => 'option:customer', 'to' => 'customer_branch'],
            ['from' => 'collect', 'output' => 'continue', 'to' => 'after_collect'],
        ],
    ];
}

function navScenario(?array $definition = null): BotScenario
{
    return BotScenario::factory()->published($definition ?? navMenuDefinition())->create();
}

function navSessionAt(BotScenario $scenario, Contact $contact, string $nodeId, array $attributes = []): BotSession
{
    return BotSession::factory()->waitingAt($nodeId)->create([
        'contact_id' => $contact->id,
        'bot_scenario_id' => $scenario->id,
        'scenario_version' => $scenario->published_version,
        ...$attributes,
    ]);
}

function navFingerprint(string $nodeId): string
{
    $definition = new ScenarioDefinition(navMenuDefinition());

    return $definition->nodeFingerprint($definition->node($nodeId));
}

/**
 * Снапшот прерванной анкеты в том виде, в каком его пишет коллектор.
 */
function navPausedSnapshot(?string $fingerprint = null): array
{
    return [
        'node_id' => 'collect',
        'fingerprint' => $fingerprint ?? navFingerprint('collect'),
        'state' => ['kind' => 'rental', 'phase' => 'collecting', 'transcript' => ['кран 25 тонн']],
        'saved_at' => now()->toIso8601String(),
    ];
}

/**
 * Живое предложение перехода в том виде, в каком его пишет движок.
 */
function navProposal(string $route, string $title = 'Перейти', string $text = 'сдаю кран', ?string $expiresAt = null): array
{
    return ['nav_proposal' => [
        'route' => $route,
        'text' => $text,
        'title' => $title,
        'expires_at' => $expiresAt ?? now()->addMinutes(30)->toIso8601String(),
    ]];
}

function navMessenger(): MockInterface
{
    return test()->mock(DereuMessenger::class);
}

function navRouter(): MockInterface
{
    return test()->mock(MenuRouter::class);
}

function navAssistant(): MockInterface
{
    return test()->mock(AiAssistant::class);
}

describe('роутер не спрашивают, пока движок справляется сам', function () {
    test('нажатая кнопка меню', function () {
        $scenario = navScenario();
        $contact = Contact::factory()->create();
        navSessionAt($scenario, $contact, 'menu');

        navRouter()->shouldNotReceive('route');
        navMessenger()->shouldReceive('sendText')->once()
            ->withArgs(fn (Contact $to, string $text): bool => $text === 'Ветка заказчика');

        app(BotEngine::class)->handle($contact, new InboundMessage(text: 'Я заказчик', replyId: 'customer'));
    });

    test('порядковый номер варианта', function () {
        $scenario = navScenario();
        $contact = Contact::factory()->create();
        navSessionAt($scenario, $contact, 'menu');

        navRouter()->shouldNotReceive('route');
        navMessenger()->shouldReceive('sendText')->once()
            ->withArgs(fn (Contact $to, string $text): bool => $text === 'Ветка заказчика');

        app(BotEngine::class)->handle($contact, new InboundMessage(text: '2'));
    });

    test('подключённый выход «Любая другая фраза»', function () {
        $definition = navMenuDefinition();
        $definition['edges'][] = ['from' => 'menu', 'output' => 'fallback', 'to' => 'fallback_hint'];
        $scenario = navScenario($definition);
        $contact = Contact::factory()->create();
        navSessionAt($scenario, $contact, 'menu');

        navRouter()->shouldNotReceive('route');
        navMessenger()->shouldReceive('sendText')->once()
            ->withArgs(fn (Contact $to, string $text): bool => $text === 'Не понял вас');

        app(BotEngine::class)->handle($contact, new InboundMessage(text: 'что-то невнятное'));
    });

    test('пустое сообщение без голоса', function () {
        $scenario = navScenario();
        $contact = Contact::factory()->create();
        navSessionAt($scenario, $contact, 'menu');

        navRouter()->shouldNotReceive('route');
        navMessenger()->shouldReceive('sendButtons')->once()
            ->withArgs(fn (Contact $to, string $text): bool => $text === 'Кто вы?');

        app(BotEngine::class)->handle($contact, new InboundMessage(text: '   '));
    });

    test('роутер вернул null — меню повторяется, как и до навигатора', function () {
        $scenario = navScenario();
        $contact = Contact::factory()->create();
        $session = navSessionAt($scenario, $contact, 'menu');

        navRouter()->shouldReceive('route')->once()->andReturnNull();
        navMessenger()->shouldReceive('sendButtons')->once()
            ->withArgs(fn (Contact $to, string $text): bool => $text === 'Кто вы?');

        app(BotEngine::class)->handle($contact, new InboundMessage(text: 'что-то невнятное'));

        expect($session->fresh())
            ->current_node_id->toBe('menu')
            ->state->toBeNull();
    });
});

describe('высокая уверенность — переход без вопросов', function () {
    test('в ветку с анкетой: блок здоровается и сразу получает написанное', function () {
        $scenario = navScenario();
        $contact = Contact::factory()->create();
        $session = navSessionAt($scenario, $contact, 'menu');

        navRouter()->shouldReceive('route')->once()
            ->withArgs(fn (BotSession $s, ScenarioDefinition $d, array $node, InboundMessage $m): bool => $node['id'] === 'menu' && $m->text === 'сдаю кран 25 тонн')
            ->andReturn(MenuRoute::toOption(['node_id' => 'menu', 'option_id' => 'supplier'], RouteConfidence::High));

        $assistant = navAssistant();
        $assistant->shouldReceive('start')->once()
            ->withArgs(fn (BotSession $s, array $node): bool => $node['id'] === 'collect')
            ->andReturn(AiOutcome::InProgress);
        $assistant->shouldReceive('resume')->once()
            ->withArgs(fn (BotSession $s, array $node, InboundMessage $m): bool => $node['id'] === 'collect' && $m->text === 'сдаю кран 25 тонн')
            ->andReturn(AiOutcome::InProgress);

        navMessenger();

        app(BotEngine::class)->handle($contact, new InboundMessage(text: 'сдаю кран 25 тонн'));

        expect($session->fresh()->current_node_id)->toBe('collect');
    });

    test('в ветку без анкеты: сообщения ветки ушли, ассистента не звали', function () {
        $scenario = navScenario();
        $contact = Contact::factory()->create();
        $session = navSessionAt($scenario, $contact, 'menu');

        navRouter()->shouldReceive('route')->once()
            ->andReturn(MenuRoute::toOption(['node_id' => 'menu', 'option_id' => 'customer'], RouteConfidence::High));

        $assistant = navAssistant();
        $assistant->shouldNotReceive('start');
        $assistant->shouldNotReceive('resume');

        navMessenger()->shouldReceive('sendText')->once()
            ->withArgs(fn (Contact $to, string $text): bool => $text === 'Ветка заказчика');

        app(BotEngine::class)->handle($contact, new InboundMessage(text: 'ищу технику в аренду'));

        expect($session->fresh())
            ->current_node_id->toBeNull()
            ->state->toBeNull();
    });

    test('возврат к прерванной анкете: рабочая память восстановлена, снапшот снят', function () {
        $scenario = navScenario();
        $contact = Contact::factory()->create();
        $session = navSessionAt($scenario, $contact, 'menu', ['paused_state' => navPausedSnapshot()]);

        navRouter()->shouldReceive('route')->once()->andReturn(MenuRoute::toResume(RouteConfidence::High));

        $assistant = navAssistant();
        $assistant->shouldNotReceive('start');
        $assistant->shouldReceive('resume')->once()
            ->withArgs(fn (BotSession $s, array $node, InboundMessage $m): bool => $node['id'] === 'collect' && $m->text === 'продолжим анкету')
            ->andReturn(AiOutcome::InProgress);

        navMessenger()->shouldReceive('sendText')->once()
            ->withArgs(fn (Contact $to, string $text): bool => $text === 'Возвращаемся к анкете — всё написанное на месте.');

        app(BotEngine::class)->handle($contact, new InboundMessage(text: 'продолжим анкету'));

        expect($session->fresh())
            ->current_node_id->toBe('collect')
            ->current_node_fingerprint->toBe(navFingerprint('collect'))
            ->paused_state->toBeNull()
            ->state->toBe(navPausedSnapshot()['state']);
    });

    test('снапшот не пережил републикацию — честная деградация к текущему меню', function () {
        $scenario = navScenario();
        $contact = Contact::factory()->create();
        $session = navSessionAt($scenario, $contact, 'menu', ['paused_state' => navPausedSnapshot('отпечаток-из-прошлой-версии')]);

        navRouter()->shouldReceive('route')->once()->andReturn(MenuRoute::toResume(RouteConfidence::High));

        $assistant = navAssistant();
        $assistant->shouldNotReceive('start');
        $assistant->shouldNotReceive('resume');

        navMessenger()->shouldReceive('sendButtons')->once()
            ->withArgs(fn (Contact $to, string $text): bool => $text === 'Кто вы?');

        app(BotEngine::class)->handle($contact, new InboundMessage(text: 'продолжим анкету'));

        expect($session->fresh())
            ->current_node_id->toBe('menu')
            ->paused_state->toBeNull()
            ->state->toBeNull();
    });

    test('узел прерванной анкеты исчез из графа — тоже деградация к меню', function () {
        $definition = navMenuDefinition();
        unset($definition['nodes'][3]);
        $definition['nodes'] = array_values($definition['nodes']);
        $scenario = navScenario($definition);
        $contact = Contact::factory()->create();
        $session = navSessionAt($scenario, $contact, 'menu', ['paused_state' => navPausedSnapshot()]);

        navRouter()->shouldReceive('route')->once()->andReturn(MenuRoute::toResume(RouteConfidence::High));

        navAssistant()->shouldNotReceive('resume');
        navMessenger()->shouldReceive('sendButtons')->once()
            ->withArgs(fn (Contact $to, string $text): bool => $text === 'Кто вы?');

        app(BotEngine::class)->handle($contact, new InboundMessage(text: 'продолжим анкету'));

        expect($session->fresh())
            ->current_node_id->toBe('menu')
            ->paused_state->toBeNull();
    });
});

describe('средняя уверенность — одна кнопка-предложение', function () {
    test('переход в раздел: текст с титулом цели и кнопка «Перейти»', function () {
        $scenario = navScenario();
        $contact = Contact::factory()->create();
        $session = navSessionAt($scenario, $contact, 'menu');

        navRouter()->shouldReceive('route')->once()
            ->andReturn(MenuRoute::toOption(['node_id' => 'menu', 'option_id' => 'supplier'], RouteConfidence::Medium));

        navMessenger()->shouldReceive('sendButtons')->once()
            ->withArgs(fn (Contact $to, string $text, array $buttons): bool => $text === 'Похоже, вам нужно «Я поставщик». Перейти?'
                && $buttons === [['id' => 'nav_confirm', 'title' => 'Перейти']]);

        app(BotEngine::class)->handle($contact, new InboundMessage(text: '  сдаю кран  '));

        $proposal = $session->fresh()->state['nav_proposal'];

        expect($session->fresh()->current_node_id)->toBe('menu')
            ->and($proposal['route'])->toBe('option:supplier')
            ->and($proposal['text'])->toBe('сдаю кран')
            ->and($proposal['title'])->toBe('Перейти')
            ->and(Carbon::parse($proposal['expires_at'])->isAfter(now()->addMinutes(29)))->toBeTrue()
            ->and(Carbon::parse($proposal['expires_at'])->isBefore(now()->addMinutes(31)))->toBeTrue();
    });

    test('возврат к анкете: свой текст и кнопка «Продолжить»', function () {
        $scenario = navScenario();
        $contact = Contact::factory()->create();
        $session = navSessionAt($scenario, $contact, 'menu', ['paused_state' => navPausedSnapshot()]);

        navRouter()->shouldReceive('route')->once()->andReturn(MenuRoute::toResume(RouteConfidence::Medium));

        navMessenger()->shouldReceive('sendButtons')->once()
            ->withArgs(fn (Contact $to, string $text, array $buttons): bool => $text === 'Похоже, вы хотите вернуться к прерванной анкете. Продолжить её?'
                && $buttons === [['id' => 'nav_confirm', 'title' => 'Продолжить']]);

        app(BotEngine::class)->handle($contact, new InboundMessage(text: 'вернёмся к тому крану'));

        $proposal = $session->fresh()->state['nav_proposal'];

        expect($proposal['route'])->toBe('resume')
            ->and($proposal['title'])->toBe('Продолжить')
            ->and($proposal['text'])->toBe('вернёмся к тому крану')
            ->and($session->fresh()->paused_state)->not->toBeNull();
    });
});

describe('подтверждение предложения', function () {
    test('нажатая кнопка исполняет сохранённый маршрут с сохранённым текстом', function () {
        $scenario = navScenario();
        $contact = Contact::factory()->create();
        $session = navSessionAt($scenario, $contact, 'menu', ['state' => navProposal('option:supplier')]);

        navRouter()->shouldNotReceive('route');

        $assistant = navAssistant();
        $assistant->shouldReceive('start')->once()->andReturn(AiOutcome::InProgress);
        $assistant->shouldReceive('resume')->once()
            ->withArgs(fn (BotSession $s, array $node, InboundMessage $m): bool => $m->text === 'сдаю кран' && $m->replyId === null)
            ->andReturn(AiOutcome::InProgress);

        navMessenger();

        app(BotEngine::class)->handle($contact, new InboundMessage(text: 'Перейти', replyId: 'nav_confirm'));

        expect($session->fresh())
            ->current_node_id->toBe('collect')
            ->state->toBeNull();
    });

    test('набранный титул кнопки работает как нажатие — регистр и пробелы неважны', function () {
        $scenario = navScenario();
        $contact = Contact::factory()->create();
        $session = navSessionAt($scenario, $contact, 'menu', ['state' => navProposal('option:customer')]);

        navRouter()->shouldNotReceive('route');
        navMessenger()->shouldReceive('sendText')->once()
            ->withArgs(fn (Contact $to, string $text): bool => $text === 'Ветка заказчика');

        app(BotEngine::class)->handle($contact, new InboundMessage(text: '  ПЕРЕЙТИ '));

        expect($session->fresh())
            ->current_node_id->toBeNull()
            ->state->toBeNull();
    });

    test('подтверждение возврата поднимает анкету из снапшота', function () {
        $scenario = navScenario();
        $contact = Contact::factory()->create();
        $session = navSessionAt($scenario, $contact, 'menu', [
            'state' => navProposal('resume', title: 'Продолжить', text: 'вернёмся к тому крану'),
            'paused_state' => navPausedSnapshot(),
        ]);

        navRouter()->shouldNotReceive('route');

        $assistant = navAssistant();
        $assistant->shouldNotReceive('start');
        $assistant->shouldReceive('resume')->once()
            ->withArgs(fn (BotSession $s, array $node, InboundMessage $m): bool => $m->text === 'вернёмся к тому крану')
            ->andReturn(AiOutcome::InProgress);

        navMessenger()->shouldReceive('sendText')->once()
            ->withArgs(fn (Contact $to, string $text): bool => $text === 'Возвращаемся к анкете — всё написанное на месте.');

        app(BotEngine::class)->handle($contact, new InboundMessage(text: 'Продолжить', replyId: 'nav_confirm'));

        expect($session->fresh())
            ->current_node_id->toBe('collect')
            ->paused_state->toBeNull()
            ->state->toBe(navPausedSnapshot()['state']);
    });

    test('любой другой ввод снимает предложение и обрабатывается как обычно', function () {
        $scenario = navScenario();
        $contact = Contact::factory()->create();
        $session = navSessionAt($scenario, $contact, 'menu', ['state' => navProposal('option:supplier')]);

        navRouter()->shouldNotReceive('route');
        navMessenger()->shouldReceive('sendText')->once()
            ->withArgs(fn (Contact $to, string $text): bool => $text === 'Ветка заказчика');

        app(BotEngine::class)->handle($contact, new InboundMessage(text: 'Я заказчик'));

        expect($session->fresh())
            ->current_node_id->toBeNull()
            ->state->toBeNull();
    });

    test('протухшее предложение падает в обычный путь устаревшей кнопки', function () {
        $scenario = navScenario();
        $contact = Contact::factory()->create();
        $session = navSessionAt($scenario, $contact, 'menu', [
            'state' => navProposal('option:supplier', expiresAt: now()->subMinute()->toIso8601String()),
        ]);

        navRouter()->shouldNotReceive('route');
        navAssistant()->shouldNotReceive('start');

        $messenger = navMessenger();
        $messenger->shouldReceive('sendText')->once()
            ->withArgs(fn (Contact $to, string $text): bool => str_contains($text, 'прежней версии'));
        $messenger->shouldReceive('sendButtons')->once()
            ->withArgs(fn (Contact $to, string $text): bool => $text === 'Кто вы?');

        app(BotEngine::class)->handle($contact, new InboundMessage(text: 'Перейти', replyId: 'nav_confirm'));

        expect($session->fresh())
            ->current_node_id->toBe('menu')
            ->state->toBeNull();
    });

    test('цель предложения исчезла из графа — тот же честный путь устаревшей кнопки', function () {
        $scenario = navScenario();
        $contact = Contact::factory()->create();
        $session = navSessionAt($scenario, $contact, 'menu', ['state' => navProposal('option:ghost')]);

        navAssistant()->shouldNotReceive('start');

        $messenger = navMessenger();
        $messenger->shouldReceive('sendText')->once()
            ->withArgs(fn (Contact $to, string $text): bool => str_contains($text, 'прежней версии'));
        $messenger->shouldReceive('sendButtons')->once()
            ->withArgs(fn (Contact $to, string $text): bool => $text === 'Кто вы?');

        app(BotEngine::class)->handle($contact, new InboundMessage(text: 'Перейти', replyId: 'nav_confirm'));

        expect($session->fresh())
            ->current_node_id->toBe('menu')
            ->state->toBeNull();
    });

    test('кнопка подтверждения из истории без предложения не роняет диалог', function () {
        $scenario = navScenario();
        $contact = Contact::factory()->create();
        $session = navSessionAt($scenario, $contact, 'menu');

        navRouter()->shouldNotReceive('route');

        $messenger = navMessenger();
        $messenger->shouldReceive('sendText')->once()
            ->withArgs(fn (Contact $to, string $text): bool => str_contains($text, 'прежней версии'));
        $messenger->shouldReceive('sendButtons')->once()
            ->withArgs(fn (Contact $to, string $text): bool => $text === 'Кто вы?');

        app(BotEngine::class)->handle($contact, new InboundMessage(text: 'Перейти', replyId: 'nav_confirm'));

        expect($session->fresh()->current_node_id)->toBe('menu');
    });
});

describe('вопрос про сервис на шаге меню', function () {
    test('бот отвечает и повторяет меню, оставаясь на том же узле', function () {
        $scenario = navScenario();
        $contact = Contact::factory()->create();
        $session = navSessionAt($scenario, $contact, 'menu');

        navRouter()->shouldReceive('route')->once()
            ->andReturn(MenuRoute::toServiceQuestion(RouteConfidence::High));

        $messenger = navMessenger();
        $messenger->shouldReceive('sendText')->once()
            ->withArgs(fn (Contact $to, string $text): bool => str_contains($text, 'отвечает наш оператор'));
        $messenger->shouldReceive('sendButtons')->once()
            ->withArgs(fn (Contact $to, string $text): bool => $text === 'Кто вы?');

        app(BotEngine::class)->handle($contact, new InboundMessage(text: 'сколько стоит размещение?'));

        expect($session->fresh())
            ->current_node_id->toBe('menu')
            ->state->toBeNull();
    });

    test('на первом сообщении меню не дублируется — оно только что ушло', function () {
        navScenario();
        $contact = Contact::factory()->create();

        navRouter()->shouldReceive('route')->once()
            ->andReturn(MenuRoute::toServiceQuestion(RouteConfidence::Medium));

        $messenger = navMessenger();
        $messenger->shouldReceive('sendText')->once()
            ->withArgs(fn (Contact $to, string $text): bool => $text === 'Привет!');
        $messenger->shouldReceive('sendText')->once()
            ->withArgs(fn (Contact $to, string $text): bool => str_contains($text, 'отвечает наш оператор'));
        $messenger->shouldReceive('sendButtons')->once()
            ->withArgs(fn (Contact $to, string $text): bool => $text === 'Кто вы?');

        app(BotEngine::class)->handle($contact, new InboundMessage(text: 'сколько стоит размещение?'));

        expect(BotSession::sole()->current_node_id)->toBe('menu');
    });
});

describe('голосовое на шаге меню', function () {
    test('расшифровка уходит роутеру вместо пустого текста', function () {
        $scenario = navScenario();
        $contact = Contact::factory()->create();
        navSessionAt($scenario, $contact, 'menu');

        test()->mock(DereuMediaDownloader::class)
            ->shouldReceive('download')->once()->with('AUDIO-1')
            ->andReturn(['contents' => 'ogg-байты', 'mime_type' => 'audio/ogg']);

        test()->mock(VoiceTranscriber::class)
            ->shouldReceive('transcribe')->once()
            ->withArgs(fn (string $contents, ?string $mime, array $links): bool => $contents === 'ogg-байты' && $mime === 'audio/ogg' && $links['contact_id'] === $contact->id)
            ->andReturn('нужен автокран на неделю');

        navRouter()->shouldReceive('route')->once()
            ->withArgs(fn (BotSession $s, ScenarioDefinition $d, array $node, InboundMessage $m): bool => $m->text === 'нужен автокран на неделю')
            ->andReturnNull();

        navMessenger()->shouldReceive('sendButtons')->once()
            ->withArgs(fn (Contact $to, string $text): bool => $text === 'Кто вы?');

        app(BotEngine::class)->handle($contact, new InboundMessage(mediaType: ListingMediaType::Audio, mediaId: 'AUDIO-1'));
    });

    test('сорвавшаяся расшифровка возвращает вчерашнее поведение — повтор меню', function () {
        $scenario = navScenario();
        $contact = Contact::factory()->create();
        navSessionAt($scenario, $contact, 'menu');

        test()->mock(DereuMediaDownloader::class)
            ->shouldReceive('download')->once()->andThrow(new RuntimeException('Dereu 403'));

        navRouter()->shouldNotReceive('route');
        navMessenger()->shouldReceive('sendButtons')->once()
            ->withArgs(fn (Contact $to, string $text): bool => $text === 'Кто вы?');

        app(BotEngine::class)->handle($contact, new InboundMessage(mediaType: ListingMediaType::Audio, mediaId: 'AUDIO-1'));
    });
});

describe('первое сообщение диалога', function () {
    test('текстовый старт: после приветствия и меню роутер получает тот же текст', function () {
        navScenario();
        $contact = Contact::factory()->create();

        navRouter()->shouldReceive('route')->once()
            ->withArgs(fn (BotSession $s, ScenarioDefinition $d, array $node, InboundMessage $m): bool => $node['id'] === 'menu' && $m->text === 'сдаю кран 25 тонн')
            ->andReturn(MenuRoute::toOption(['node_id' => 'menu', 'option_id' => 'supplier'], RouteConfidence::High));

        $assistant = navAssistant();
        $assistant->shouldReceive('start')->once()->andReturn(AiOutcome::InProgress);
        $assistant->shouldReceive('resume')->once()
            ->withArgs(fn (BotSession $s, array $node, InboundMessage $m): bool => $m->text === 'сдаю кран 25 тонн')
            ->andReturn(AiOutcome::InProgress);

        $messenger = navMessenger();
        $messenger->shouldReceive('sendText')->once()
            ->withArgs(fn (Contact $to, string $text): bool => $text === 'Привет!');
        $messenger->shouldReceive('sendButtons')->once();

        app(BotEngine::class)->handle($contact, new InboundMessage(text: 'сдаю кран 25 тонн'));

        expect(BotSession::sole()->current_node_id)->toBe('collect');
    });

    test('кнопочный старт роутер не дёргает', function () {
        navScenario();
        $contact = Contact::factory()->create();

        navRouter()->shouldNotReceive('route');

        $messenger = navMessenger();
        $messenger->shouldReceive('sendText')->once()
            ->withArgs(fn (Contact $to, string $text): bool => $text === 'Привет!');
        $messenger->shouldReceive('sendButtons')->once();

        app(BotEngine::class)->handle($contact, new InboundMessage(text: 'Я поставщик', replyId: 'supplier'));

        expect(BotSession::sole()->current_node_id)->toBe('menu');
    });

    test('старт, упершийся не в меню, роутер не дёргает', function () {
        BotScenario::factory()->published([
            'nodes' => [
                ['id' => 'start', 'type' => 'start'],
                ['id' => 'collect', 'type' => 'ai', 'task' => 'collect_listing'],
            ],
            'edges' => [
                ['from' => 'start', 'output' => 'continue', 'to' => 'collect'],
            ],
        ])->create();
        $contact = Contact::factory()->create();

        navRouter()->shouldNotReceive('route');
        navAssistant()->shouldReceive('start')->once()->andReturn(AiOutcome::InProgress);
        navMessenger();

        app(BotEngine::class)->handle($contact, new InboundMessage(text: 'сдаю кран'));

        expect(BotSession::sole()->current_node_id)->toBe('collect');
    });
});
