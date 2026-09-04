<?php

use App\Enums\AiOutcome;
use App\Enums\ListingMediaType;
use App\Enums\RouteConfidence;
use App\Exceptions\OutboundRequestBlocked;
use App\Models\BotReplyText;
use App\Models\BotScenario;
use App\Models\BotSession;
use App\Models\Contact;
use App\Models\Listing;
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

/**
 * Тот же граф, но между опцией «Я поставщик» и ai-узлом стоит текстовый
 * блок: конструктор это разрешает, и движок проходит его автопродвижением.
 * Реальный опубликованный сценарий устроен именно так — прямая связка
 * «опция → анкета» есть только в дефолтной установке.
 */
function navMenuDefinitionThroughText(): array
{
    $definition = navMenuDefinition();
    $definition['nodes'][] = ['id' => 'intro', 'type' => 'text', 'text' => 'Отлично! Расскажите о технике'];
    $definition['edges'] = array_map(
        fn (array $edge): array => $edge['output'] === 'option:supplier' ? [...$edge, 'to' => 'intro'] : $edge,
        $definition['edges'],
    );
    $definition['edges'][] = ['from' => 'intro', 'output' => 'continue', 'to' => 'collect'];

    return $definition;
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

    test('возврат к анкете, чей черновик уже ушёл, не обещает «всё написанное на месте»', function () {
        // Черновик прерванной анкеты успели отправить на проверку из
        // веб-кабинета: коллектор завершит блок честным статусом, и
        // обещание «всё написанное на месте» прямо перед этим — ложь.
        $scenario = navScenario();
        $contact = Contact::factory()->create();
        $listing = Listing::factory()->pendingModeration()->create();
        $snapshot = navPausedSnapshot();
        $snapshot['state']['draft_id'] = $listing->id;
        $session = navSessionAt($scenario, $contact, 'menu', ['paused_state' => $snapshot]);

        navRouter()->shouldReceive('route')->once()->andReturn(MenuRoute::toResume(RouteConfidence::High));

        $assistant = navAssistant();
        $assistant->shouldNotReceive('start');
        $assistant->shouldReceive('resume')->once()->andReturn(AiOutcome::Completed);

        // Единственный текст — от узла после анкеты: NavResumed не уходит.
        navMessenger()->shouldReceive('sendText')->once()
            ->withArgs(fn (Contact $to, string $text): bool => $text === 'Анкета закрыта');

        app(BotEngine::class)->handle($contact, new InboundMessage(text: 'продолжим анкету'));

        expect($session->fresh()->paused_state)->toBeNull();
    });

    test('опция ведёт в узел прерванной анкеты через промежуточный блок — это возврат, а не вторая анкета', function () {
        $scenario = navScenario(navMenuDefinitionThroughText());
        $contact = Contact::factory()->create();
        $session = navSessionAt($scenario, $contact, 'menu', ['paused_state' => navPausedSnapshot()]);

        navRouter()->shouldReceive('route')->once()
            ->andReturn(MenuRoute::toOption(['node_id' => 'menu', 'option_id' => 'supplier'], RouteConfidence::High));

        $assistant = navAssistant();
        $assistant->shouldNotReceive('start');
        $assistant->shouldReceive('resume')->once()
            ->withArgs(fn (BotSession $s, array $node, InboundMessage $m): bool => $node['id'] === 'collect' && $m->text === 'хочу дополнить объявление про кран')
            ->andReturn(AiOutcome::InProgress);

        // Единственное сообщение — про возврат: текст промежуточного блока
        // не уходит, потому что диалог никуда и не переходил.
        navMessenger()->shouldReceive('sendText')->once()
            ->withArgs(fn (Contact $to, string $text): bool => $text === 'Возвращаемся к анкете — всё написанное на месте.');

        app(BotEngine::class)->handle($contact, new InboundMessage(text: 'хочу дополнить объявление про кран'));

        expect($session->fresh())
            ->current_node_id->toBe('collect')
            ->paused_state->toBeNull()
            ->state->toBe(navPausedSnapshot()['state']);
    });

    test('фото с подписью доезжает до анкеты вместе с самим фото', function () {
        $scenario = navScenario();
        $contact = Contact::factory()->create();
        navSessionAt($scenario, $contact, 'menu');

        navRouter()->shouldReceive('route')->once()
            ->andReturn(MenuRoute::toOption(['node_id' => 'menu', 'option_id' => 'supplier'], RouteConfidence::High));

        $assistant = navAssistant();
        $assistant->shouldReceive('start')->once()->andReturn(AiOutcome::InProgress);
        $assistant->shouldReceive('resume')->once()
            ->withArgs(fn (BotSession $s, array $node, InboundMessage $m): bool => $m->text === 'сдаю кран 25 тонн'
                && $m->mediaId === 'IMG-1'
                && $m->mediaType === ListingMediaType::Photo)
            ->andReturn(AiOutcome::InProgress);

        navMessenger();

        app(BotEngine::class)->handle($contact, new InboundMessage(
            text: 'сдаю кран 25 тонн',
            mediaType: ListingMediaType::Photo,
            mediaId: 'IMG-1',
        ));
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

    test('процент в операторской редакции текста не роняет отправку', function () {
        $scenario = navScenario();
        $contact = Contact::factory()->create();
        navSessionAt($scenario, $contact, 'menu');

        // sprintf на такой строке упал бы ValueError, и контакт получил бы
        // тишину вместо предложения: «% т» — не спецификатор формата.
        BotReplyText::query()->create([
            'key' => 'nav_route_offer',
            'text' => 'У нас 100% техники под задачу. Похоже, вам нужно «%s». Перейти?',
        ]);

        navRouter()->shouldReceive('route')->once()
            ->andReturn(MenuRoute::toOption(['node_id' => 'menu', 'option_id' => 'supplier'], RouteConfidence::Medium));

        navMessenger()->shouldReceive('sendButtons')->once()
            ->withArgs(fn (Contact $to, string $text, array $buttons): bool => $text === 'У нас 100% техники под задачу. Похоже, вам нужно «Я поставщик». Перейти?');

        app(BotEngine::class)->handle($contact, new InboundMessage(text: 'сдаю кран'));

        expect(BotSession::sole()->state['nav_proposal']['route'])->toBe('option:supplier');
    });

    test('сорвавшаяся отправка предложения не оставляет записанного предложения', function () {
        $scenario = navScenario();
        $contact = Contact::factory()->create();
        $session = navSessionAt($scenario, $contact, 'menu');

        navRouter()->shouldReceive('route')->once()
            ->andReturn(MenuRoute::toOption(['node_id' => 'menu', 'option_id' => 'supplier'], RouteConfidence::Medium));

        navMessenger()->shouldReceive('sendButtons')->once()->andThrow(new RuntimeException('Dereu 503'));

        // Джоб вебхука отработает ретрай: предложение от упавшей попытки
        // только затёрло бы то, которое дойдёт со следующей.
        expect(fn () => app(BotEngine::class)->handle($contact, new InboundMessage(text: 'сдаю кран')))
            ->toThrow(RuntimeException::class);

        expect($session->fresh()->state)->toBeNull();
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

    test('протухшее предложение, подтверждённое набранным титулом, отвечает тем же текстом', function () {
        $scenario = navScenario();
        $contact = Contact::factory()->create();
        $session = navSessionAt($scenario, $contact, 'menu', [
            'state' => navProposal('option:supplier', expiresAt: now()->subMinute()->toIso8601String()),
        ]);

        // Набранный титул — то же нажатие: платить за модель за слово,
        // которое движок только что распознал сам, не за что.
        navRouter()->shouldNotReceive('route');
        navAssistant()->shouldNotReceive('start');

        $messenger = navMessenger();
        $messenger->shouldReceive('sendText')->once()
            ->withArgs(fn (Contact $to, string $text): bool => str_contains($text, 'прежней версии'));
        $messenger->shouldReceive('sendButtons')->once()
            ->withArgs(fn (Contact $to, string $text): bool => $text === 'Кто вы?');

        app(BotEngine::class)->handle($contact, new InboundMessage(text: '  перейти '));

        expect($session->fresh())
            ->current_node_id->toBe('menu')
            ->state->toBeNull();
    });

    test('нажатая кнопка графа с тем же титулом предложение не подтверждает', function () {
        $definition = navMenuDefinition();
        $definition['nodes'][] = ['id' => 'other_menu', 'type' => 'buttons', 'text' => 'Что дальше?', 'options' => [
            ['id' => 'go_on', 'title' => 'Продолжить'],
        ]];
        $definition['edges'][] = ['from' => 'other_menu', 'output' => 'option:go_on', 'to' => 'customer_branch'];
        $scenario = navScenario($definition);
        $contact = Contact::factory()->create();
        $session = navSessionAt($scenario, $contact, 'menu', [
            'state' => navProposal('resume', title: 'Продолжить', text: 'вернёмся к тому крану'),
            'paused_state' => navPausedSnapshot(),
        ]);

        navRouter()->shouldNotReceive('route');

        $assistant = navAssistant();
        $assistant->shouldNotReceive('start');
        $assistant->shouldNotReceive('resume');

        navMessenger()->shouldReceive('sendText')->once()
            ->withArgs(fn (Contact $to, string $text): bool => $text === 'Ветка заказчика');

        app(BotEngine::class)->handle($contact, new InboundMessage(text: 'Продолжить', replyId: 'go_on'));

        expect($session->fresh())
            ->current_node_id->toBeNull()
            ->state->toBeNull();
    });

    test('цель предложения исчезла из графа — тот же честный путь устаревшей кнопки', function () {
        $scenario = navScenario();
        $contact = Contact::factory()->create();
        $session = navSessionAt($scenario, $contact, 'menu', ['state' => navProposal('option:ghost')]);

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

    test('низкая уверенность — ровно прежнее поведение, ответа про сервис не будет', function () {
        $scenario = navScenario();
        $contact = Contact::factory()->create();
        $session = navSessionAt($scenario, $contact, 'menu');

        navRouter()->shouldReceive('route')->once()
            ->andReturn(MenuRoute::toServiceQuestion(RouteConfidence::Low));

        navMessenger()->shouldReceive('sendButtons')->once()
            ->withArgs(fn (Contact $to, string $text): bool => $text === 'Кто вы?');

        app(BotEngine::class)->handle($contact, new InboundMessage(text: 'а это вообще бот?'));

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
    test('голосовое, заблокированное гвардом этой машины, не выдаётся за нерасшифрованное', function () {
        $scenario = navScenario();
        $contact = Contact::factory()->create();
        navSessionAt($scenario, $contact, 'menu');

        test()->mock(DereuMediaDownloader::class)
            ->shouldReceive('download')->once()->with('AUDIO-BLOCKED')
            ->andThrow(OutboundRequestBlocked::host('api.dereu.example'));

        navMessenger()->shouldNotReceive('sendButtons');

        expect(fn () => app(BotEngine::class)->handle(
            $contact,
            new InboundMessage(mediaType: ListingMediaType::Audio, mediaId: 'AUDIO-BLOCKED'),
        ))->toThrow(OutboundRequestBlocked::class);
    });

    test('расшифровка уходит и роутеру, и анкете — без второго скачивания', function () {
        $scenario = navScenario();
        $contact = Contact::factory()->create();
        $session = navSessionAt($scenario, $contact, 'menu');

        test()->mock(DereuMediaDownloader::class)
            ->shouldReceive('download')->once()->with('AUDIO-1')
            ->andReturn(['contents' => 'ogg-байты', 'mime_type' => 'audio/ogg']);

        test()->mock(VoiceTranscriber::class)
            ->shouldReceive('transcribe')->once()
            ->withArgs(fn (string $contents, ?string $mime, array $links): bool => $contents === 'ogg-байты' && $mime === 'audio/ogg' && $links['contact_id'] === $contact->id)
            ->andReturn('нужен автокран на неделю');

        navRouter()->shouldReceive('route')->once()
            ->withArgs(fn (BotSession $s, ScenarioDefinition $d, array $node, InboundMessage $m): bool => $m->text === 'нужен автокран на неделю')
            ->andReturn(MenuRoute::toOption(['node_id' => 'menu', 'option_id' => 'supplier'], RouteConfidence::High));

        $assistant = navAssistant();
        $assistant->shouldReceive('start')->once()->andReturn(AiOutcome::InProgress);
        // Медиа в анкету не переносится: аудио уже скачано и расшифровано,
        // второй заход стоил бы второй транскрипции.
        $assistant->shouldReceive('resume')->once()
            ->withArgs(fn (BotSession $s, array $node, InboundMessage $m): bool => $m->text === 'нужен автокран на неделю'
                && $m->mediaId === null
                && $m->mediaType === null)
            ->andReturn(AiOutcome::InProgress);

        navMessenger();

        app(BotEngine::class)->handle($contact, new InboundMessage(mediaType: ListingMediaType::Audio, mediaId: 'AUDIO-1'));

        expect($session->fresh()->current_node_id)->toBe('collect');
    });

    test('шестое голосовое за час не скачивается и не расшифровывается', function () {
        $scenario = navScenario();
        $contact = Contact::factory()->create();
        navSessionAt($scenario, $contact, 'menu');

        test()->mock(DereuMediaDownloader::class)
            ->shouldReceive('download')->times(5)
            ->andReturn(['contents' => 'ogg-байты', 'mime_type' => 'audio/ogg']);

        test()->mock(VoiceTranscriber::class)
            ->shouldReceive('transcribe')->times(5)->andReturn('нужен автокран на неделю');

        navRouter()->shouldReceive('route')->times(5)->andReturnNull();
        navMessenger()->shouldReceive('sendButtons')->times(6)
            ->withArgs(fn (Contact $to, string $text): bool => $text === 'Кто вы?');

        foreach (range(1, 6) as $number) {
            app(BotEngine::class)->handle($contact, new InboundMessage(mediaType: ListingMediaType::Audio, mediaId: 'AUDIO-'.$number));
        }
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

    test('возврат через сутки: приветствие, меню и подъём прерванной анкеты', function () {
        // Флагманский сценарий снапшота: TTL в 48 часов выбран так, чтобы
        // пережить 24-часовое окно, поэтому вернувшийся назавтра поставщик
        // приходит именно этим путём — новым диалогом, а не ответом на меню.
        $scenario = navScenario();
        $contact = Contact::factory()->create();
        $session = navSessionAt($scenario, $contact, 'menu', [
            'paused_state' => [...navPausedSnapshot(), 'saved_at' => now()->subHours(30)->toIso8601String()],
        ]);
        $session->forceFill(['updated_at' => now()->subHours(30)])->saveQuietly();

        navRouter()->shouldReceive('route')->once()
            ->withArgs(fn (BotSession $s, ScenarioDefinition $d, array $node, InboundMessage $m): bool => $node['id'] === 'menu' && $m->text === 'давай закончим с краном')
            ->andReturn(MenuRoute::toResume(RouteConfidence::High));

        $assistant = navAssistant();
        $assistant->shouldNotReceive('start');
        $assistant->shouldReceive('resume')->once()
            ->withArgs(fn (BotSession $s, array $node, InboundMessage $m): bool => $node['id'] === 'collect' && $m->text === 'давай закончим с краном')
            ->andReturn(AiOutcome::InProgress);

        $messenger = navMessenger();
        $messenger->shouldReceive('sendText')->once()
            ->withArgs(fn (Contact $to, string $text): bool => $text === 'Привет!');
        $messenger->shouldReceive('sendButtons')->once()
            ->withArgs(fn (Contact $to, string $text): bool => $text === 'Кто вы?');
        $messenger->shouldReceive('sendText')->once()
            ->withArgs(fn (Contact $to, string $text): bool => $text === 'Возвращаемся к анкете — всё написанное на месте.');

        app(BotEngine::class)->handle($contact, new InboundMessage(text: 'давай закончим с краном'));

        expect($session->fresh())
            ->current_node_id->toBe('collect')
            ->current_node_fingerprint->toBe(navFingerprint('collect'))
            ->paused_state->toBeNull()
            ->state->toBe(navPausedSnapshot()['state']);
    });

    test('возврат через сутки при средней уверенности: меню и предложение продолжить', function () {
        $scenario = navScenario();
        $contact = Contact::factory()->create();
        $session = navSessionAt($scenario, $contact, 'menu', [
            'paused_state' => [...navPausedSnapshot(), 'saved_at' => now()->subHours(30)->toIso8601String()],
        ]);
        $session->forceFill(['updated_at' => now()->subHours(30)])->saveQuietly();

        navRouter()->shouldReceive('route')->once()->andReturn(MenuRoute::toResume(RouteConfidence::Medium));
        navAssistant()->shouldNotReceive('resume');

        $messenger = navMessenger();
        $messenger->shouldReceive('sendText')->once()
            ->withArgs(fn (Contact $to, string $text): bool => $text === 'Привет!');
        $messenger->shouldReceive('sendButtons')->once()
            ->withArgs(fn (Contact $to, string $text): bool => $text === 'Кто вы?');
        $messenger->shouldReceive('sendButtons')->once()
            ->withArgs(fn (Contact $to, string $text, array $buttons): bool => $text === 'Похоже, вы хотите вернуться к прерванной анкете. Продолжить её?'
                && $buttons === [['id' => 'nav_confirm', 'title' => 'Продолжить']]);

        app(BotEngine::class)->handle($contact, new InboundMessage(text: 'кран ещё актуален?'));

        expect($session->fresh())
            ->current_node_id->toBe('menu')
            ->paused_state->not->toBeNull()
            ->and($session->fresh()->state['nav_proposal']['route'])->toBe('resume');
    });

    test('новый диалог не трогает снапшот прерванной анкеты', function () {
        $scenario = navScenario();
        $contact = Contact::factory()->create();
        $session = navSessionAt($scenario, $contact, 'menu', ['paused_state' => navPausedSnapshot()]);
        $session->forceFill(['updated_at' => now()->subHours(30)])->saveQuietly();

        navRouter()->shouldReceive('route')->once()->andReturnNull();

        $messenger = navMessenger();
        $messenger->shouldReceive('sendText')->once()
            ->withArgs(fn (Contact $to, string $text): bool => $text === 'Привет!');
        $messenger->shouldReceive('sendButtons')->once();

        app(BotEngine::class)->handle($contact, new InboundMessage(text: 'что-то невнятное'));

        expect($session->fresh()->paused_state)->not->toBeNull();
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

    test('текст, совпавший с вариантом меню, роутер не дёргает', function () {
        navScenario();
        $contact = Contact::factory()->create();

        // Граф отвечает сам — платить за модель не за что. Ответом на меню
        // это сообщение всё равно не станет: меню ушло тем же ходом.
        navRouter()->shouldNotReceive('route');

        $messenger = navMessenger();
        $messenger->shouldReceive('sendText')->once()
            ->withArgs(fn (Contact $to, string $text): bool => $text === 'Привет!');
        $messenger->shouldReceive('sendButtons')->once();

        app(BotEngine::class)->handle($contact, new InboundMessage(text: ' я поставщик '));

        expect(BotSession::sole()->current_node_id)->toBe('menu');
    });

    test('подключённый выход «Любая другая фраза» роутер не дёргает и на первом сообщении', function () {
        $definition = navMenuDefinition();
        $definition['edges'][] = ['from' => 'menu', 'output' => 'fallback', 'to' => 'fallback_hint'];
        navScenario($definition);
        $contact = Contact::factory()->create();

        navRouter()->shouldNotReceive('route');

        $messenger = navMessenger();
        $messenger->shouldReceive('sendText')->once()
            ->withArgs(fn (Contact $to, string $text): bool => $text === 'Привет!');
        $messenger->shouldReceive('sendButtons')->once();

        app(BotEngine::class)->handle($contact, new InboundMessage(text: 'сдаю кран 25 тонн'));

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
