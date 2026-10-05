<?php

use App\Ai\Agents\ListingExtractionAgent;
use App\Ai\Agents\SearchQueryExtractionAgent;
use App\Enums\AiOperationType;
use App\Enums\AiOutcome;
use App\Enums\BotScenarioTrigger;
use App\Enums\ListingMediaType;
use App\Enums\ListingStatus;
use App\Enums\RouteConfidence;
use App\Models\AiOperation;
use App\Models\BotScenario;
use App\Models\BotSession;
use App\Models\Contact;
use App\Models\CustomerRequest;
use App\Models\Listing;
use App\Services\Ai\CustomerSearchAssistant;
use App\Services\Ai\SupplierListingCollector;
use App\Services\Bot\AiAssistant;
use App\Services\Bot\BotEngine;
use App\Services\Bot\InboundMessage;
use App\Services\Bot\MenuRoute;
use App\Services\Bot\MenuRouter;
use App\Services\DereuMediaDownloader;
use App\Services\DereuMessenger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Ai\Embeddings;
use Laravel\Ai\Transcription;
use Mockery\MockInterface;

uses(RefreshDatabase::class);

// Сквозной путь типового главного диалога: движок, опубликованная схема и
// настоящие ассистенты. Проверяется то, что видит человек, — какие сообщения
// пришли и какие нет, — а не то, какой исход вернул ассистент.
beforeEach(function () {
    Embeddings::fake();
    ListingExtractionAgent::fake()->preventStrayPrompts();
    SearchQueryExtractionAgent::fake()->preventStrayPrompts();
});

const MAIN_MENU_TEXT = 'Что вас интересует?';

function typicalMainDialog(): BotScenario
{
    test()->artisan('bot:install-default-scenario', ['--only' => BotScenarioTrigger::InboundMessage->value])
        ->assertSuccessful();

    return BotScenario::main();
}

/**
 * Контакт, стоящий на шаге типового диалога, — как если бы он дошёл до
 * него сам: с отпечатком шага и отметкой, что бот его уже встречал.
 *
 * @param  array<string, mixed>|null  $state
 */
function branchSessionAt(BotScenario $scenario, string $nodeId, ?array $state = null): BotSession
{
    $definition = $scenario->publishedDefinition();

    return BotSession::factory()->waitingAt($nodeId)->create([
        'bot_scenario_id' => $scenario->id,
        'scenario_version' => $scenario->published_version,
        'current_node_fingerprint' => $definition->nodeFingerprint($definition->node($nodeId)),
        'last_dialog_ended_at' => now()->subMinutes(5),
        'state' => $state,
    ]);
}

/**
 * Анкета аренды на подтверждении сводки с собственным черновиком контакта.
 */
function branchSessionOnSummary(BotScenario $scenario): BotSession
{
    $session = branchSessionAt($scenario, 'collect_rental');
    $draft = Listing::factory()->create(['contact_id' => $session->contact_id]);

    $session->update(['state' => [
        'kind' => 'rental',
        'phase' => 'confirming',
        'attempts' => 0,
        'transcript' => ['Сдаю трактор в Шымкенте, 10000 тг/час'],
        'fields' => ['description' => 'Трактор в аренду'],
        'draft_id' => $draft->id,
    ]]);

    return $session;
}

/**
 * Поиск аренды, в котором уже что-то написано.
 *
 * @param  array<string, mixed>  $state
 */
function branchSessionInSearch(BotScenario $scenario, array $state = []): BotSession
{
    return branchSessionAt($scenario, 'search_rental', [
        'kind' => 'rental',
        'phase' => 'searching',
        'attempts' => 0,
        'clarifications' => 0,
        'transcript' => ['нужен кран'],
        'query' => null,
        'offered' => [],
        ...$state,
    ]);
}

/**
 * Записывает всё, что бот отправил, в порядке отправки: вид сообщения,
 * получатель, текст и id кнопок.
 *
 * @return ArrayObject<int, array{kind: string, to: int, text: string, buttons: list<string>}>
 */
function recordOutbound(): ArrayObject
{
    $sent = new ArrayObject;
    $record = fn (string $kind, Contact $to, string $text, array $buttons = []) => $sent->append([
        'kind' => $kind,
        'to' => $to->id,
        'text' => $text,
        'buttons' => array_column($buttons, 'id'),
    ]);

    /** @var MockInterface $messenger */
    $messenger = test()->mock(DereuMessenger::class);
    $messenger->shouldReceive('sendText')
        ->andReturnUsing(fn (Contact $to, string $text) => $record('text', $to, $text));
    $messenger->shouldReceive('sendButtons')
        ->andReturnUsing(fn (Contact $to, string $text, array $buttons) => $record('buttons', $to, $text, $buttons));
    $messenger->shouldReceive('sendCtaUrl')
        ->andReturnUsing(fn (Contact $to, string $text) => $record('cta', $to, $text));
    $messenger->shouldReceive('sendList')
        ->andReturnUsing(fn (Contact $to, string $text, string $button, array $rows) => $record('list', $to, $text, $rows));

    return $sent;
}

/**
 * @param  ArrayObject<int, array{kind: string, to: int, text: string, buttons: list<string>}>  $sent
 * @return list<array{0: string, 1: string}>
 */
function outboundTo(ArrayObject $sent, int $contactId): array
{
    return collect($sent->getArrayCopy())
        ->where('to', $contactId)
        ->map(fn (array $message): array => [$message['kind'], $message['text']])
        ->values()
        ->all();
}

function silentNavigator(): MockInterface
{
    $router = test()->mock(MenuRouter::class);
    $router->shouldReceive('route')->andReturnNull()->byDefault();

    return $router;
}

function pressInDialog(BotSession $session, InboundMessage $message): void
{
    app(BotEngine::class)->handle($session->contact, $message);
}

describe('ветка завершилась сама — последней остаётся её реплика', function () {
    test('анкета ушла на проверку: «Готово!» без меню следом', function () {
        $scenario = typicalMainDialog();
        $session = branchSessionOnSummary($scenario);
        $sent = recordOutbound();

        pressInDialog($session, new InboundMessage(text: 'Да, отправить', replyId: SupplierListingCollector::BUTTON_SUBMIT));

        expect(outboundTo($sent, $session->contact_id))->toBe([
            ['text', 'Готово! Объявление ушло на проверку. Как только модератор решит — сразу напишем.'],
        ])
            ->and(Listing::sole()->status)->toBe(ListingStatus::PendingModeration)
            ->and($session->fresh())
            ->current_node_id->toBeNull()
            ->state->toBeNull();
    });

    test('«Исправить»: ссылка на веб-форму говорит, что диалог закончен, — и он закончен', function () {
        $scenario = typicalMainDialog();
        $session = branchSessionOnSummary($scenario);
        $sent = recordOutbound();

        pressInDialog($session, new InboundMessage(text: 'Исправить', replyId: SupplierListingCollector::BUTTON_EDIT));

        expect(outboundTo($sent, $session->contact_id))->toBe([
            ['cta', 'Чтобы изменить объявление, нажмите на кнопку ниже. Диалог в чате на этом закончим, черновик сохранён.'],
        ])
            ->and($session->fresh()->current_node_id)->toBeNull();
    });

    test('лимит уточнений исчерпан: ссылка на веб-форму без меню следом', function () {
        ListingExtractionAgent::fake([[
            'title' => 'Аренда трактора',
            'category' => categoryNamed('Трактор')->name,
            'new_category' => null,
            'brand' => null,
            'description' => 'Трактор в аренду',
            'location' => locationNamed('г.Шымкент')->name,
            'location_detail' => null,
            'price' => null,
            'clarifying_question' => '',
            'clarifying_field' => null,
            'summary' => 'Трактор, Шымкент',
        ]]);
        $scenario = typicalMainDialog();
        $session = branchSessionAt($scenario, 'collect_rental', [
            'kind' => 'rental',
            'phase' => 'collecting',
            'attempts' => 3,
            'transcript' => ['Сдаю трактор в Шымкенте'],
            'fields' => [],
            'draft_id' => null,
        ]);
        $sent = recordOutbound();

        pressInDialog($session, new InboundMessage(text: 'не знаю'));

        expect(outboundTo($sent, $session->contact_id))->toBe([
            ['cta', 'Часть данных из переписки собрать не вышло. Удобнее закончить в форме по кнопке ниже — всё собранное уже там.'],
        ])
            ->and($session->fresh()->current_node_id)->toBeNull();
    });

    test('отказ словами в анкете: «Хорошо, остановимся.» без меню следом', function () {
        ListingExtractionAgent::fake([['user_intent' => 'abandoned']]);
        $scenario = typicalMainDialog();
        $session = branchSessionAt($scenario, 'collect_rental', [
            'kind' => 'rental',
            'phase' => 'collecting',
            'attempts' => 0,
            'transcript' => [],
            'fields' => [],
            'draft_id' => null,
        ]);
        $sent = recordOutbound();

        pressInDialog($session, new InboundMessage(text: 'я передумал'));

        expect(outboundTo($sent, $session->contact_id))->toBe([['text', 'Хорошо, остановимся.']])
            ->and($session->fresh()->current_node_id)->toBeNull();
    });

    test('поиск трижды ничего не нашёл: прощание с каталогом без меню следом', function () {
        SearchQueryExtractionAgent::fake([[
            'subject' => 'вертолёт', 'location' => null, 'location_any' => true, 'clarifying_question' => '',
        ]]);
        $scenario = typicalMainDialog();
        $session = branchSessionInSearch($scenario, ['attempts' => 2, 'transcript' => []]);
        $sent = recordOutbound();

        pressInDialog($session, new InboundMessage(text: 'вертолёт'));

        expect(outboundTo($sent, $session->contact_id))->toBe([
            ['cta', 'Подходящего сейчас не нашлось — так бывает, база пополняется каждый день. Загляните в каталог: вдруг что-то уже появилось.'],
        ])
            ->and($session->fresh()->current_node_id)->toBeNull();
    });

    test('отказ словами в поиске: «Хорошо, остановимся.» без меню следом', function () {
        SearchQueryExtractionAgent::fake([[
            'subject' => null, 'location' => null, 'location_any' => false,
            'clarifying_question' => '', 'user_intent' => 'abandoned',
        ]]);
        $scenario = typicalMainDialog();
        $session = branchSessionInSearch($scenario);
        $sent = recordOutbound();

        pressInDialog($session, new InboundMessage(text: 'спасибо, уже не нужно'));

        expect(outboundTo($sent, $session->contact_id))->toBe([['text', 'Хорошо, остановимся.']])
            ->and($session->fresh()->current_node_id)->toBeNull();
    });

    test('заявка из прежнего чат-списка: «Заявка … ушла поставщику» без меню следом', function () {
        // Окно поставщика открыто: уведомление о заявке уходит ему кнопками,
        // а проверяется здесь только то, что получил заказчик.
        $supplier = Contact::factory()->withOpenSessionWindow()->create();
        $listing = Listing::factory()->published()->for($supplier, 'supplier')->create(['title' => 'Автокран 25 т']);
        $scenario = typicalMainDialog();
        $session = branchSessionInSearch($scenario, [
            'phase' => 'choosing',
            'query' => 'нужен кран',
            'offered' => [$listing->id],
        ]);
        $sent = recordOutbound();

        pressInDialog($session, new InboundMessage(replyId: "listing:{$listing->id}"));

        expect(outboundTo($sent, $session->contact_id))->toBe([
            ['text', 'Заявка по «Автокран 25 т» ушла поставщику. Как только он ответит — сразу напишем.'],
        ])
            ->and(CustomerRequest::sole()->listing_id)->toBe($listing->id)
            ->and($session->fresh()->current_node_id)->toBeNull();
    });

    test('«Мои объявления»: ссылка на кабинет без меню следом', function (string $menuNodeId, string $optionId) {
        $scenario = typicalMainDialog();
        $session = branchSessionAt($scenario, $menuNodeId);
        $sent = recordOutbound();

        pressInDialog($session, new InboundMessage(text: 'Мои объявления', replyId: $optionId));

        expect(outboundTo($sent, $session->contact_id))->toBe([
            ['cta', 'Ваши объявления собраны в кабинете: статусы, причины отклонения, снятие с публикации. Кнопка ниже откроет его без пароля.'],
        ])
            ->and($session->fresh()->current_node_id)->toBeNull();
    })->with([
        'раздел аренды' => ['menu_rental', 'my'],
        'раздел ремонта' => ['menu_repair', 'my_repair'],
        'раздел водителей' => ['menu_driver', 'my_driver'],
    ]);
});

describe('после завершившейся ветки меню приходит на следующее сообщение', function () {
    test('любое непонятое сообщение даёт главное меню — без приветствия', function () {
        $scenario = typicalMainDialog();
        $session = branchSessionOnSummary($scenario);
        silentNavigator();
        $sent = recordOutbound();

        pressInDialog($session, new InboundMessage(text: 'Да, отправить', replyId: SupplierListingCollector::BUTTON_SUBMIT));
        pressInDialog($session, new InboundMessage(text: 'а что дальше'));

        expect(outboundTo($sent, $session->contact_id))->toBe([
            ['text', 'Готово! Объявление ушло на проверку. Как только модератор решит — сразу напишем.'],
            ['buttons', MAIN_MENU_TEXT],
        ])
            ->and($sent[1]['buttons'])->toBe(['kind_rental', 'kind_repair', 'kind_driver'])
            ->and($session->fresh()->current_node_id)->toBe('main_menu');
    });

    test('кнопка ассистента из более раннего сообщения даёт меню один раз — без «кнопка устарела» и без дублей', function (InboundMessage $press) {
        $scenario = typicalMainDialog();
        $session = branchSessionOnSummary($scenario);
        silentNavigator()->shouldNotReceive('route');
        $sent = recordOutbound();

        pressInDialog($session, new InboundMessage(text: 'Да, отправить', replyId: SupplierListingCollector::BUTTON_SUBMIT));
        pressInDialog($session, $press);

        expect(outboundTo($sent, $session->contact_id))->toBe([
            ['text', 'Готово! Объявление ушло на проверку. Как только модератор решит — сразу напишем.'],
            ['buttons', MAIN_MENU_TEXT],
        ])
            ->and($session->fresh())
            ->current_node_id->toBe('main_menu')
            ->state->toBeNull();
    })->with([
        '«В меню» под прежним вопросом анкеты' => [new InboundMessage(text: 'В меню', replyId: SupplierListingCollector::BUTTON_MENU)],
        '«В меню» под заголовком выдачи поиска' => [new InboundMessage(text: 'В меню', replyId: CustomerSearchAssistant::BUTTON_MENU)],
        '«Назад» под приглашением блока' => [new InboundMessage(text: 'Назад', replyId: SupplierListingCollector::BUTTON_BACK)],
        '«Да, отправить» той же сводки ещё раз' => [new InboundMessage(text: 'Да, отправить', replyId: SupplierListingCollector::BUTTON_SUBMIT)],
        'кнопка прежней версии сценария' => [new InboundMessage(text: 'Устаревшая', replyId: 'kind_from_old_version')],
    ]);

    test('кнопка схемы из более раннего сообщения ведёт сразу в свою ветку — без главного меню', function (InboundMessage $press, array $expected, ?string $node) {
        // В разделе аренды есть что искать — иначе ветка поиска вместо
        // приглашения ответила бы, что раздел пуст.
        Listing::factory()->published()->create(['description' => 'Автокран 25 тонн', 'price' => '20000 тг/ч']);
        $scenario = typicalMainDialog();
        $session = branchSessionOnSummary($scenario);
        silentNavigator()->shouldNotReceive('route');
        $sent = recordOutbound();

        pressInDialog($session, new InboundMessage(text: 'Да, отправить', replyId: SupplierListingCollector::BUTTON_SUBMIT));
        pressInDialog($session, $press);

        // Знакомому контакту приветствие не приходит, главное меню — тоже:
        // ответом на нажатие сразу идёт то, что дала бы кнопка на только
        // что показанном меню.
        expect(outboundTo($sent, $session->contact_id))->toBe([
            ['text', 'Готово! Объявление ушло на проверку. Как только модератор решит — сразу напишем.'],
            ...$expected,
        ])
            ->and($session->fresh()->current_node_id)->toBe($node);
    })->with([
        'раздел из прежнего главного меню' => [
            new InboundMessage(text: 'Ремонт спецтехники', replyId: 'kind_repair'),
            [['buttons', 'Ремонт спецтехники. Вы мастер или ищете мастера?']],
            'menu_repair',
        ],
        'роль с прежнего экрана раздела' => [
            new InboundMessage(text: 'Я ищу спецтехнику', replyId: 'rent_seek'),
            [['buttons', 'Расскажите, что нужно и в каком городе — можно голосом. Например: «нужен кран 25 тонн, Шымкент».']],
            'search_rental',
        ],
        'анкета с прежнего экрана раздела' => [
            new InboundMessage(text: 'Я водитель', replyId: 'driver'),
            [['buttons', 'Расскажите о себе: на какой технике работаете, какое удостоверение, сколько лет стажа, в каком городе, готовы ли выезжать. Напишите или наговорите голосом.']],
            'collect_driver',
        ],
        '«Мои объявления» с прежнего экрана раздела' => [
            new InboundMessage(text: 'Мои объявления', replyId: 'my_repair'),
            [['cta', 'Ваши объявления собраны в кабинете: статусы, причины отклонения, снятие с публикации. Кнопка ниже откроет его без пароля.']],
            null,
        ],
    ]);

    test('кнопка раздела из более раннего сообщения через сутки тишины — тоже сразу в раздел', function () {
        $scenario = typicalMainDialog();
        $session = branchSessionAt($scenario, 'main_menu');
        $session->forceFill(['updated_at' => now()->subHours(30)])->saveQuietly();
        silentNavigator()->shouldNotReceive('route');
        $sent = recordOutbound();

        pressInDialog($session, new InboundMessage(text: 'Водитель / машинист', replyId: 'kind_driver'));

        expect(outboundTo($sent, $session->contact_id))->toBe([
            ['buttons', 'Водители и машинисты. Вы водитель или ищете водителя?'],
        ])
            ->and($sent[0]['buttons'])->toBe(['driver', 'driver_seek', 'my_driver'])
            ->and($session->fresh()->current_node_id)->toBe('menu_driver');
    });

    test('содержательное сообщение ведёт сразу в ветку — меню не показывается', function () {
        $scenario = typicalMainDialog();
        $session = branchSessionOnSummary($scenario);
        $sent = recordOutbound();

        pressInDialog($session, new InboundMessage(text: 'Да, отправить', replyId: SupplierListingCollector::BUTTON_SUBMIT));

        test()->mock(MenuRouter::class)->shouldReceive('route')->once()
            ->withArgs(fn (BotSession $s, $definition, array $node, InboundMessage $m): bool => $node['id'] === 'main_menu'
                && $m->text === 'нужен экскаватор в Шымкенте')
            ->andReturn(MenuRoute::toOption(['node_id' => 'menu_rental', 'option_id' => 'rent_seek'], RouteConfidence::High));
        test()->mock(AiAssistant::class)->shouldReceive('start')->once()
            ->withArgs(fn (BotSession $s, array $node, ?InboundMessage $carried): bool => $node['id'] === 'search_rental'
                && $carried?->text === 'нужен экскаватор в Шымкенте')
            ->andReturn(AiOutcome::InProgress);

        pressInDialog($session, new InboundMessage(text: 'нужен экскаватор в Шымкенте'));

        // Первое сообщение нового диалога классифицируется до показа меню:
        // бот понял, куда вести, — ни меню, ни приветствия.
        expect(outboundTo($sent, $session->contact_id))->toBe([
            ['text', 'Готово! Объявление ушло на проверку. Как только модератор решит — сразу напишем.'],
        ])
            ->and($session->fresh()->current_node_id)->toBe('search_rental');
    });

    test('«спасибо» после «Готово!» остаётся без ответа', function () {
        $scenario = typicalMainDialog();
        $session = branchSessionOnSummary($scenario);
        $sent = recordOutbound();

        pressInDialog($session, new InboundMessage(text: 'Да, отправить', replyId: SupplierListingCollector::BUTTON_SUBMIT));

        test()->mock(MenuRouter::class)->shouldReceive('route')->once()
            ->andReturn(MenuRoute::toAcknowledgement(RouteConfidence::High));

        pressInDialog($session, new InboundMessage(text: 'спасибо'));

        // Подтверждение ничего не спрашивает: ни приветствия, ни меню.
        expect(outboundTo($sent, $session->contact_id))->toBe([
            ['text', 'Готово! Объявление ушло на проверку. Как только модератор решит — сразу напишем.'],
        ])
            ->and($session->fresh()->current_node_id)->toBeNull();
    });
});

describe('просьба о меню по-прежнему показывает его сразу', function () {
    test('подтверждённый выход из непустой анкеты: строка о черновике и главное меню', function () {
        $scenario = typicalMainDialog();
        $session = branchSessionOnSummary($scenario);
        $sent = recordOutbound();

        pressInDialog($session, new InboundMessage(text: 'В меню', replyId: SupplierListingCollector::BUTTON_MENU));
        pressInDialog($session, new InboundMessage(text: 'Да, в меню', replyId: SupplierListingCollector::BUTTON_EXIT_CONFIRM));

        expect(outboundTo($sent, $session->contact_id))->toBe([
            ['buttons', 'Прервать анкету? Всё написанное сохранится — сможете продолжить позже.'],
            ['text', 'Черновик сохранили — он ждёт в кабинете.'],
            ['buttons', MAIN_MENU_TEXT],
        ])
            ->and($session->fresh())
            ->current_node_id->toBe('main_menu')
            ->state->toBeNull()
            // Снимок прерванной анкеты живёт отдельно от рабочей памяти.
            ->and($session->fresh()->paused_state['node_id'])->toBe('collect_rental');
    });

    test('выход из анкеты словами: меню без вопроса «Прервать анкету?»', function () {
        ListingExtractionAgent::fake([['user_intent' => 'menu']]);
        $scenario = typicalMainDialog();
        $session = branchSessionOnSummary($scenario);
        $sent = recordOutbound();

        pressInDialog($session, new InboundMessage(text: 'покажите другие разделы'));

        expect(outboundTo($sent, $session->contact_id))->toBe([
            ['text', 'Черновик сохранили — он ждёт в кабинете.'],
            ['buttons', MAIN_MENU_TEXT],
        ])
            ->and($session->fresh()->current_node_id)->toBe('main_menu');
    });

    test('в поиске меню приходит одно, без других сообщений', function (InboundMessage $message, ?array $intake) {
        if ($intake !== null) {
            SearchQueryExtractionAgent::fake([$intake]);
        }

        $scenario = typicalMainDialog();
        $session = branchSessionInSearch($scenario);
        $sent = recordOutbound();

        pressInDialog($session, $message);

        expect(outboundTo($sent, $session->contact_id))->toBe([['buttons', MAIN_MENU_TEXT]])
            ->and($session->fresh())
            ->current_node_id->toBe('main_menu')
            ->state->toBeNull();
    })->with([
        'кнопка «В меню»' => [new InboundMessage(text: 'В меню', replyId: CustomerSearchAssistant::BUTTON_MENU), null],
        'набранное «в меню»' => [new InboundMessage(text: ' в МЕНЮ '), null],
        'старая кнопка «Назад» при начатом поиске' => [new InboundMessage(text: 'Назад', replyId: CustomerSearchAssistant::BUTTON_BACK), null],
        'просьба словами' => [new InboundMessage(text: 'хочу в другой раздел'), [
            'subject' => null, 'location' => null, 'location_any' => false,
            'clarifying_question' => '', 'user_intent' => 'menu',
        ]],
        // Не просьба о меню, а сообщение не про поиск: тот же выход, без
        // реплики и без поиска по прежнему запросу.
        'сообщение не про поиск' => [new InboundMessage(text: 'ааааааа'), [
            'subject' => 'кран', 'location' => null, 'location_any' => false,
            'clarifying_question' => '', 'user_intent' => 'off_topic',
        ]],
    ]);

    test('после выдачи одним сообщением «меню» словами показывает главное меню', function () {
        SearchQueryExtractionAgent::fake([
            ['subject' => 'автокран', 'location' => null, 'location_any' => true, 'clarifying_question' => ''],
            ['subject' => null, 'location' => null, 'location_any' => false, 'clarifying_question' => '', 'user_intent' => 'menu'],
        ]);
        Listing::factory()->published()->create([
            'category_id' => categoryNamed('Автокран')->id, 'description' => 'Автокран 25 тонн', 'price' => '20000 тг/ч',
        ]);
        $scenario = typicalMainDialog();
        $session = branchSessionInSearch($scenario, ['transcript' => []]);
        $sent = recordOutbound();

        pressInDialog($session, new InboundMessage(text: 'нужен автокран'));

        // Выдача — одно сообщение с кнопкой каталога, без отдельного
        // сообщения с «В меню»; поиск остаётся открытым и ждёт уточнения.
        expect(outboundTo($sent, $session->contact_id))->toBe([
            ['cta', 'Нашлись варианты по запросу «автокран». Смотрите их в каталоге по кнопке ниже — запрос уже подставлен, там же поиск и фильтры. Выберите подходящий — заявка сразу уйдёт поставщику. Чтобы вернуться в меню, напишите «меню».'],
        ])
            ->and($session->fresh())
            ->current_node_id->toBe('search_rental')
            ->state->phase->toBe('searching');

        pressInDialog($session, new InboundMessage(text: 'меню'));

        expect(array_slice(outboundTo($sent, $session->contact_id), 1))->toBe([['buttons', MAIN_MENU_TEXT]])
            ->and($session->fresh()->current_node_id)->toBe('main_menu');
    });

    test('после пустой выдачи одним сообщением старая кнопка «В меню» по-прежнему выводит в меню', function () {
        SearchQueryExtractionAgent::fake([
            ['subject' => 'вертолёт', 'location' => null, 'location_any' => true, 'clarifying_question' => ''],
        ]);
        $scenario = typicalMainDialog();
        $session = branchSessionInSearch($scenario, ['transcript' => []]);
        $sent = recordOutbound();

        pressInDialog($session, new InboundMessage(text: 'вертолёт'));

        expect(outboundTo($sent, $session->contact_id))->toBe([
            ['cta', 'Пока по такому запросу пусто. Попробуйте сказать иначе — вид техники и город, например: «кран 25 тонн, Шымкент». Или загляните в каталог по кнопке ниже — там все объявления, база пополняется каждый день. Чтобы вернуться в меню, напишите «меню».'],
        ])
            ->and($session->fresh())
            ->current_node_id->toBe('search_rental')
            ->state->attempts->toBe(1);

        // «В меню» под сообщением, отправленным до объединения, остаётся
        // в чатах и работает как раньше.
        pressInDialog($session, new InboundMessage(text: 'В меню', replyId: CustomerSearchAssistant::BUTTON_MENU));

        expect(array_slice(outboundTo($sent, $session->contact_id), 1))->toBe([['buttons', MAIN_MENU_TEXT]])
            ->and($session->fresh()->current_node_id)->toBe('main_menu');
    });

    test('«Назад» у нетронутого поиска ведёт на экран раздела, а не в главное меню', function () {
        $scenario = typicalMainDialog();
        $session = branchSessionInSearch($scenario, ['transcript' => []]);
        $sent = recordOutbound();

        pressInDialog($session, new InboundMessage(text: 'Назад', replyId: CustomerSearchAssistant::BUTTON_BACK));

        expect(outboundTo($sent, $session->contact_id))->toBe([
            ['buttons', 'Аренда спецтехники. Вы предлагаете технику или ищете?'],
        ])
            ->and($session->fresh()->current_node_id)->toBe('menu_rental');
    });
});

describe('поиск после исхода: через час текст читается как от вернувшегося клиента', function () {
    test('раздел, названный словами, ведёт сразу в ветку, и поиск начинается с чистого листа', function () {
        SearchQueryExtractionAgent::fake([
            ['subject' => 'автокран', 'location' => null, 'location_any' => true, 'clarifying_question' => ''],
            ['subject' => 'экскаватор', 'location' => null, 'location_any' => true, 'clarifying_question' => ''],
        ])->preventStrayPrompts();
        Listing::factory()->published()->create([
            'category_id' => categoryNamed('Автокран')->id, 'description' => 'Автокран 25 тонн', 'price' => 'договорная',
        ]);
        $scenario = typicalMainDialog();
        $session = branchSessionInSearch($scenario, ['transcript' => []]);
        $sent = recordOutbound();

        pressInDialog($session, new InboundMessage(text: 'нужен автокран'));

        $this->travel(2)->hours();

        test()->mock(MenuRouter::class)->shouldReceive('route')->once()
            ->withArgs(fn (BotSession $s, $definition, array $node, InboundMessage $m): bool => $node['id'] === 'main_menu'
                && $m->text === 'нужен экскаватор')
            ->andReturn(MenuRoute::toOption(['node_id' => 'menu_rental', 'option_id' => 'rent_seek'], RouteConfidence::High));

        pressInDialog($session, new InboundMessage(text: 'нужен экскаватор'));

        // Прежний запрос в новый поиск не попал: разбор видел одно новое
        // сообщение, а счётчики начаты заново.
        SearchQueryExtractionAgent::assertPrompted(fn ($prompt): bool => $prompt->contains('нужен экскаватор')
            && ! $prompt->contains('автокран'));

        expect(outboundTo($sent, $session->contact_id))->toBe([
            ['cta', 'Нашлись варианты по запросу «автокран». Смотрите их в каталоге по кнопке ниже — запрос уже подставлен, там же поиск и фильтры. Выберите подходящий — заявка сразу уйдёт поставщику. Чтобы вернуться в меню, напишите «меню».'],
            ['cta', 'Пока по такому запросу пусто. Попробуйте сказать иначе — вид техники и город, например: «кран 25 тонн, Шымкент». Или загляните в каталог по кнопке ниже — там все объявления, база пополняется каждый день. Чтобы вернуться в меню, напишите «меню».'],
        ])
            ->and($session->fresh()->current_node_id)->toBe('search_rental')
            ->and($session->fresh()->state['transcript'])->toBe(['нужен экскаватор'])
            ->and($session->fresh()->state['attempts'])->toBe(1);
    });

    test('приветствие через час после пустой выдачи получает главное меню, а не повтор поиска', function () {
        // Второго разбора нет — приветствие не уточняет старый запрос.
        SearchQueryExtractionAgent::fake([
            ['subject' => 'вертолёт', 'location' => null, 'location_any' => true, 'clarifying_question' => ''],
        ])->preventStrayPrompts();
        $scenario = typicalMainDialog();
        $session = branchSessionInSearch($scenario, ['transcript' => []]);
        $sent = recordOutbound();
        test()->mock(MenuRouter::class)->shouldReceive('route')->once()
            ->withArgs(fn (BotSession $s, $definition, array $node, InboundMessage $m): bool => $node['id'] === 'main_menu'
                && $m->text === 'Здравствуйте')
            ->andReturnNull();

        pressInDialog($session, new InboundMessage(text: 'вертолёт'));

        $this->travel(90)->minutes();

        pressInDialog($session, new InboundMessage(text: 'Здравствуйте'));

        expect(outboundTo($sent, $session->contact_id))->toBe([
            ['cta', 'Пока по такому запросу пусто. Попробуйте сказать иначе — вид техники и город, например: «кран 25 тонн, Шымкент». Или загляните в каталог по кнопке ниже — там все объявления, база пополняется каждый день. Чтобы вернуться в меню, напишите «меню».'],
            ['buttons', MAIN_MENU_TEXT],
        ])
            ->and($session->fresh())
            ->current_node_id->toBe('main_menu')
            ->state->toBeNull();
    });

    test('голосовое через час расшифровывается один раз и уходит навигатору', function () {
        SearchQueryExtractionAgent::fake()->preventStrayPrompts();
        Transcription::fake(['нужен экскаватор']);
        test()->mock(DereuMediaDownloader::class)
            ->shouldReceive('download')->once()->with('voice-1')
            ->andReturn(['contents' => 'OGG-BYTES', 'mime_type' => 'audio/ogg']);
        $scenario = typicalMainDialog();
        $session = branchSessionInSearch($scenario, [
            'query' => 'кран',
            'outcome_at' => now()->subHours(2)->toIso8601String(),
        ]);
        $sent = recordOutbound();
        test()->mock(MenuRouter::class)->shouldReceive('route')->once()
            ->withArgs(fn (BotSession $s, $definition, array $node, InboundMessage $m): bool => $m->text === 'нужен экскаватор')
            ->andReturnNull();

        pressInDialog($session, new InboundMessage(mediaType: ListingMediaType::Audio, mediaId: 'voice-1'));

        expect(outboundTo($sent, $session->contact_id))->toBe([['buttons', MAIN_MENU_TEXT]])
            ->and(AiOperation::query()->where('operation', AiOperationType::Transcription)->count())->toBe(1);
    });

    test('кнопка «В меню» прежнего сообщения через час — по-прежнему просто меню', function () {
        SearchQueryExtractionAgent::fake()->preventStrayPrompts();
        $scenario = typicalMainDialog();
        $session = branchSessionInSearch($scenario, [
            'query' => 'кран',
            'outcome_at' => now()->subHours(2)->toIso8601String(),
        ]);
        $sent = recordOutbound();
        test()->mock(MenuRouter::class)->shouldNotReceive('route');

        pressInDialog($session, new InboundMessage(text: 'В меню', replyId: CustomerSearchAssistant::BUTTON_MENU));

        expect(outboundTo($sent, $session->contact_id))->toBe([['buttons', MAIN_MENU_TEXT]])
            ->and($session->fresh()->current_node_id)->toBe('main_menu');
    });
});

describe('черновик ушёл из-под анкеты, пока человек был в чате', function () {
    /**
     * Анкета на сводке, черновик которой поставщик тем временем отправил на
     * проверку из веб-кабинета.
     */
    function branchSessionWithSubmittedDraft(BotScenario $scenario): BotSession
    {
        $session = branchSessionOnSummary($scenario);

        Listing::findOrFail($session->state['draft_id'])->submitForModeration();

        return $session;
    }

    /**
     * Ответ разбора, полный данных: если бы хоть что-то из него дошло до
     * объявления, оно изменилось бы.
     *
     * @return array<string, mixed>
     */
    function extractionWithIntent(string $intent): array
    {
        return [
            'title' => 'Совсем другой заголовок',
            'category' => categoryNamed('Экскаватор')->name,
            'new_category' => null,
            'brand' => null,
            'description' => 'Совсем другое описание',
            'location' => locationNamed('г.Алматы')->name,
            'location_detail' => null,
            'price' => '99000 тг/час',
            'clarifying_question' => '',
            'clarifying_field' => null,
            'summary' => 'Экскаватор, Алматы, 99000 тг/ч',
            'user_intent' => $intent,
        ];
    }

    test('просьба о меню словами: ответ о статусе и ровно одно главное меню', function () {
        ListingExtractionAgent::fake([extractionWithIntent('menu')]);
        $scenario = typicalMainDialog();
        $session = branchSessionWithSubmittedDraft($scenario);
        $listingBefore = Listing::sole()->getAttributes();
        $sent = recordOutbound();

        pressInDialog($session, new InboundMessage(text: 'покажите другие разделы'));

        expect(outboundTo($sent, $session->contact_id))->toBe([
            ['text', 'Это объявление уже ушло на проверку. Как только модератор решит — сразу напишем.'],
            ['buttons', MAIN_MENU_TEXT],
        ])
            ->and($sent[1]['buttons'])->toBe(['kind_rental', 'kind_repair', 'kind_driver'])
            ->and($session->fresh())
            ->current_node_id->toBe('main_menu')
            ->state->toBeNull()
            ->paused_state->toBeNull()
            // Объявление не изменилось ни в одном поле и осталось одно.
            ->and(Listing::sole()->getAttributes())->toBe($listingBefore)
            ->and(Listing::sole()->status)->toBe(ListingStatus::PendingModeration);
        ListingExtractionAgent::assertPrompted(fn ($prompt): bool => $prompt->contains('покажите другие разделы'));
    });

    test('обычное дополнение к анкете: только ответ о статусе, меню не приходит', function () {
        ListingExtractionAgent::fake([extractionWithIntent('task')]);
        $scenario = typicalMainDialog();
        $session = branchSessionWithSubmittedDraft($scenario);
        $listingBefore = Listing::sole()->getAttributes();
        $sent = recordOutbound();

        pressInDialog($session, new InboundMessage(text: 'цена теперь 99000 в час'));

        expect(outboundTo($sent, $session->contact_id))->toBe([
            ['text', 'Это объявление уже ушло на проверку. Как только модератор решит — сразу напишем.'],
        ])
            ->and($session->fresh())
            ->current_node_id->toBeNull()
            ->state->toBeNull()
            ->and(Listing::sole()->getAttributes())->toBe($listingBefore);
    });

    test('кнопка «В меню»: ответ о статусе и меню, классификатор не спрошен', function () {
        $scenario = typicalMainDialog();
        $session = branchSessionWithSubmittedDraft($scenario);
        $listingBefore = Listing::sole()->getAttributes();
        $sent = recordOutbound();

        pressInDialog($session, new InboundMessage(text: 'В меню', replyId: SupplierListingCollector::BUTTON_MENU));

        expect(outboundTo($sent, $session->contact_id))->toBe([
            ['text', 'Это объявление уже ушло на проверку. Как только модератор решит — сразу напишем.'],
            ['buttons', MAIN_MENU_TEXT],
        ])
            ->and($session->fresh()->current_node_id)->toBe('main_menu')
            ->and(Listing::sole()->getAttributes())->toBe($listingBefore);
        ListingExtractionAgent::assertNeverPrompted();
    });

    test('классификатор недоступен: человек всё равно получает ответ о статусе', function () {
        ListingExtractionAgent::fake([fn () => throw new RuntimeException('AI недоступен')]);
        $scenario = typicalMainDialog();
        $session = branchSessionWithSubmittedDraft($scenario);
        $sent = recordOutbound();

        pressInDialog($session, new InboundMessage(text: 'покажите другие разделы'));

        expect(outboundTo($sent, $session->contact_id))->toBe([
            ['text', 'Это объявление уже ушло на проверку. Как только модератор решит — сразу напишем.'],
        ])
            ->and($session->fresh()->current_node_id)->toBeNull();
    });

    test('после ответа о статусе следующее сообщение начинает новый диалог и классификатор анкеты больше не спрашивают', function () {
        // Блок завершён первым же сообщением, так что лишний вызов разбора
        // случается не чаще одного раза на такую анкету.
        ListingExtractionAgent::fake([extractionWithIntent('task')]);
        $scenario = typicalMainDialog();
        $session = branchSessionWithSubmittedDraft($scenario);
        silentNavigator();
        $sent = recordOutbound();

        pressInDialog($session, new InboundMessage(text: 'цена теперь 99000 в час'));
        pressInDialog($session, new InboundMessage(text: 'и ещё фото пришлю'));

        expect(outboundTo($sent, $session->contact_id))->toBe([
            ['text', 'Это объявление уже ушло на проверку. Как только модератор решит — сразу напишем.'],
            ['buttons', MAIN_MENU_TEXT],
        ])
            ->and($session->fresh()->current_node_id)->toBe('main_menu');
        ListingExtractionAgent::assertNotPrompted(fn ($prompt): bool => $prompt->contains('и ещё фото пришлю'));

        expect(AiOperation::query()->where('operation', AiOperationType::ListingExtraction)->count())->toBe(1);
    });
});

describe('прерванная анкета', function () {
    test('после выхода в меню текст, похожий на её ответ, возвращает в анкету', function () {
        ListingExtractionAgent::fake([[
            'title' => 'Аренда трактора',
            'category' => categoryNamed('Трактор')->name,
            'new_category' => null,
            'brand' => null,
            'description' => 'Трактор в аренду с водителем',
            'location' => locationNamed('г.Шымкент')->name,
            'location_detail' => null,
            'price' => '12000 тг/час',
            'clarifying_question' => '',
            'clarifying_field' => null,
            'summary' => 'Трактор, Шымкент, 12000 тг/ч',
        ]]);
        $scenario = typicalMainDialog();
        $session = branchSessionOnSummary($scenario);
        $draftId = $session->state['draft_id'];
        $sent = recordOutbound();

        pressInDialog($session, new InboundMessage(text: 'В меню', replyId: SupplierListingCollector::BUTTON_MENU));
        pressInDialog($session, new InboundMessage(text: 'Да, в меню', replyId: SupplierListingCollector::BUTTON_EXIT_CONFIRM));

        test()->mock(MenuRouter::class)->shouldReceive('route')->once()
            ->andReturn(MenuRoute::toResume(RouteConfidence::High));

        pressInDialog($session, new InboundMessage(text: 'цена 12000 в час'));

        $fresh = $session->fresh();

        expect(array_column(outboundTo($sent, $session->contact_id), 1))
            ->toHaveCount(5)
            ->sequence(
                fn ($text) => $text->toBe('Прервать анкету? Всё написанное сохранится — сможете продолжить позже.'),
                fn ($text) => $text->toBe('Черновик сохранили — он ждёт в кабинете.'),
                fn ($text) => $text->toBe(MAIN_MENU_TEXT),
                fn ($text) => $text->toBe('Возвращаемся к анкете — всё написанное на месте.'),
                fn ($text) => $text->toContain('12000'),
            )
            ->and($fresh)
            ->current_node_id->toBe('collect_rental')
            ->paused_state->toBeNull()
            // Та же анкета и тот же черновик, а не вторая рядом с первой.
            ->and($fresh->state['draft_id'])->toBe($draftId)
            ->and(Listing::count())->toBe(1);
    });

    test('кнопка раздела после закончившегося диалога ведёт в раздел, а вернуться к анкете оттуда можно', function () {
        ListingExtractionAgent::fake([[
            'title' => 'Аренда трактора',
            'category' => categoryNamed('Трактор')->name,
            'new_category' => null,
            'brand' => null,
            'description' => 'Трактор в аренду с водителем',
            'location' => locationNamed('г.Шымкент')->name,
            'location_detail' => null,
            'price' => '12000 тг/час',
            'clarifying_question' => '',
            'clarifying_field' => null,
            'summary' => 'Трактор, Шымкент, 12000 тг/ч',
        ]]);
        $scenario = typicalMainDialog();
        $session = branchSessionOnSummary($scenario);
        $draftId = $session->state['draft_id'];
        silentNavigator()->shouldNotReceive('route');
        $sent = recordOutbound();

        pressInDialog($session, new InboundMessage(text: 'В меню', replyId: SupplierListingCollector::BUTTON_MENU));
        pressInDialog($session, new InboundMessage(text: 'Да, в меню', replyId: SupplierListingCollector::BUTTON_EXIT_CONFIRM));

        // Сутки тишины: диалог закончился, а анкета ещё ждёт возврата.
        $this->travel(25)->hours();
        $before = count($sent);

        pressInDialog($session, new InboundMessage(text: 'Аренда спецтехники', replyId: 'kind_rental'));

        expect(array_slice(outboundTo($sent, $session->contact_id), $before))->toBe([
            ['buttons', 'Аренда спецтехники. Вы предлагаете технику или ищете?'],
        ])
            ->and($session->fresh())
            ->current_node_id->toBe('menu_rental')
            ->and($session->fresh()->paused_state['node_id'])->toBe('collect_rental');

        test()->mock(MenuRouter::class)->shouldReceive('route')->once()
            ->withArgs(fn (BotSession $s, $definition, array $node, InboundMessage $m): bool => $node['id'] === 'menu_rental'
                && $m->text === 'цена 12000 в час')
            ->andReturn(MenuRoute::toResume(RouteConfidence::High));

        pressInDialog($session, new InboundMessage(text: 'цена 12000 в час'));

        $fresh = $session->fresh();

        expect(array_column(array_slice(outboundTo($sent, $session->contact_id), $before), 1))
            ->toHaveCount(3)
            ->sequence(
                fn ($text) => $text->toBe('Аренда спецтехники. Вы предлагаете технику или ищете?'),
                fn ($text) => $text->toBe('Возвращаемся к анкете — всё написанное на месте.'),
                fn ($text) => $text->toContain('12000'),
            )
            ->and($fresh)
            ->current_node_id->toBe('collect_rental')
            ->paused_state->toBeNull()
            ->and($fresh->state['draft_id'])->toBe($draftId)
            ->and(Listing::count())->toBe(1);
    });

    test('кнопка самой анкеты после закончившегося диалога — то же, что на только что показанном меню', function (bool $dialogEnded) {
        $scenario = typicalMainDialog();
        $session = branchSessionOnSummary($scenario);
        silentNavigator()->shouldNotReceive('route');
        $sent = recordOutbound();

        pressInDialog($session, new InboundMessage(text: 'В меню', replyId: SupplierListingCollector::BUTTON_MENU));
        pressInDialog($session, new InboundMessage(text: 'Да, в меню', replyId: SupplierListingCollector::BUTTON_EXIT_CONFIRM));
        pressInDialog($session, new InboundMessage(text: 'Аренда спецтехники', replyId: 'kind_rental'));

        expect($session->fresh()->paused_state['node_id'])->toBe('collect_rental');

        if ($dialogEnded) {
            $this->travel(25)->hours();
        }

        $before = count($sent);

        pressInDialog($session, new InboundMessage(text: 'Я сдаю спецтехнику', replyId: 'rent_out'));

        // Нажатая кнопка — явное начало новой анкеты: приходит её
        // приглашение, а сохранённая обесценивается — в обоих случаях
        // одинаково.
        expect(array_slice(outboundTo($sent, $session->contact_id), $before))->toBe([
            ['buttons', 'Расскажите, что вы предлагаете: пришлите фото, голосовое или напишите текстом — что это, в каком городе и по какой цене.'],
        ])
            ->and($session->fresh())
            ->current_node_id->toBe('collect_rental')
            ->paused_state->toBeNull()
            ->and($session->fresh()->state['draft_id'])->toBeNull();
    })->with([
        'на только что показанном экране раздела' => [false],
        'после суток тишины' => [true],
    ]);
});

describe('«Старт» ведёт в анкету, а меню открывается из неё', function () {
    /**
     * Схема, которую публикация пропускает: от «Старта» — приветствие и
     * сразу анкета аренды, а разделы открывает её выход «В меню». Меню, на
     * котором можно встать молча, на пути от «Старта» нет вовсе.
     */
    function entryQuestionnaireDialog(): BotScenario
    {
        return BotScenario::factory()->published([
            'nodes' => [
                ['id' => 'start', 'type' => 'start'],
                ['id' => 'greeting', 'type' => 'text', 'text' => 'Здравствуйте! Это сервис спецтехники.'],
                ['id' => 'collect_rental', 'type' => 'ai', 'task' => 'collect_listing', 'kind' => 'rental'],
                ['id' => 'main_menu', 'type' => 'buttons', 'text' => MAIN_MENU_TEXT, 'options' => [
                    ['id' => 'kind_rental', 'title' => 'Аренда спецтехники'],
                ]],
                ['id' => 'menu_rental', 'type' => 'buttons', 'text' => 'Аренда спецтехники. Вы предлагаете технику или ищете?', 'options' => [
                    ['id' => 'rent_out', 'title' => 'Я сдаю спецтехнику'],
                    ['id' => 'rent_seek', 'title' => 'Я ищу спецтехнику'],
                    ['id' => 'my', 'title' => 'Мои объявления'],
                ]],
                ['id' => 'search_rental', 'type' => 'ai', 'task' => 'customer_search', 'kind' => 'rental'],
                ['id' => 'my_listings', 'type' => 'my_listings', 'text' => 'Ваши объявления — в кабинете.'],
            ],
            'edges' => [
                ['from' => 'start', 'output' => 'continue', 'to' => 'greeting'],
                ['from' => 'start', 'output' => 'returning', 'to' => 'collect_rental'],
                ['from' => 'greeting', 'output' => 'continue', 'to' => 'collect_rental'],
                ['from' => 'collect_rental', 'output' => 'menu', 'to' => 'main_menu'],
                ['from' => 'main_menu', 'output' => 'option:kind_rental', 'to' => 'menu_rental'],
                ['from' => 'menu_rental', 'output' => 'option:rent_out', 'to' => 'collect_rental'],
                ['from' => 'menu_rental', 'output' => 'option:rent_seek', 'to' => 'search_rental'],
                ['from' => 'menu_rental', 'output' => 'option:my', 'to' => 'my_listings'],
                ['from' => 'search_rental', 'output' => 'menu', 'to' => 'main_menu'],
            ],
        ])->create();
    }

    test('кнопка из более раннего сообщения ведёт в свою ветку, не начиная анкету от «Старта»', function (bool $silentForADay, InboundMessage $press, array $expected, string $node) {
        // Чужое опубликованное объявление аренды: в разделе есть что искать,
        // иначе ветка поиска вместо приглашения ответила бы, что раздел пуст.
        Listing::factory()->published()->create(['description' => 'Автокран 25 тонн', 'price' => '20000 тг/ч']);
        $scenario = entryQuestionnaireDialog();
        $session = branchSessionOnSummary($scenario);
        $draftId = $session->state['draft_id'];
        silentNavigator()->shouldNotReceive('route');
        $sent = recordOutbound();

        // Поставщик вышел из анкеты с прогрессом и дошёл до экрана раздела.
        pressInDialog($session, new InboundMessage(text: 'В меню', replyId: SupplierListingCollector::BUTTON_MENU));
        pressInDialog($session, new InboundMessage(text: 'Да, в меню', replyId: SupplierListingCollector::BUTTON_EXIT_CONFIRM));
        pressInDialog($session, new InboundMessage(text: 'Аренда спецтехники', replyId: 'kind_rental'));

        // Диалог закончился: веткой «Мои объявления» или сутками тишины.
        if ($silentForADay) {
            $this->travel(25)->hours();
        } else {
            pressInDialog($session, new InboundMessage(text: 'Мои объявления', replyId: 'my'));
            expect($session->fresh()->current_node_id)->toBeNull();
        }

        $before = count($sent);

        pressInDialog($session, $press);

        // Ровно ветка нажатой кнопки: ни приветствия знакомому контакту,
        // ни приглашения анкеты, к которой ведёт «Старт».
        expect(array_slice(outboundTo($sent, $session->contact_id), $before))->toBe($expected)
            ->and($session->fresh())
            ->current_node_id->toBe($node)
            // Прерванная анкета осталась — как после нажатия на показанном меню.
            ->and($session->fresh()->paused_state['node_id'])->toBe('collect_rental')
            ->and($session->fresh()->paused_state['state']['draft_id'])->toBe($draftId)
            ->and(Listing::query()->where('contact_id', $session->contact_id)->count())->toBe(1);
    })->with([
        'ветка завершилась' => [false],
        'сутки тишины' => [true],
    ])->with([
        'кнопка главного меню' => [
            new InboundMessage(text: 'Аренда спецтехники', replyId: 'kind_rental'),
            [['buttons', 'Аренда спецтехники. Вы предлагаете технику или ищете?']],
            'menu_rental',
        ],
        'кнопка поиска с экрана раздела' => [
            new InboundMessage(text: 'Я ищу спецтехнику', replyId: 'rent_seek'),
            [['buttons', 'Расскажите, что нужно и в каком городе — можно голосом. Например: «нужен кран 25 тонн, Шымкент».']],
            'search_rental',
        ],
    ]);
});

/**
 * Ответ пустого раздела водителей — в типовом диалоге объявлений нет вовсе.
 */
const EMPTY_DRIVER_SECTION_TEXT = 'Раздел «Водитель / машинист» только наполняется — объявлений пока нет. Загляните позже: база пополняется каждый день.';

describe('пустой раздел поиска отвечается одним сообщением, каким бы путём ни вёл вход', function () {
    // Запрос не собирается: ни приглашения, ни разбора требований (строгий
    // фейк разборщика упал бы на любом вызове), ни кнопки каталога. Дальше —
    // как у любой завершившейся ветки: диалог закончен, меню не приходит.
    function expectEmptyDriverSectionOnly(ArrayObject $sent, BotSession $session, int $before = 0): void
    {
        expect(array_slice(outboundTo($sent, $session->contact_id), $before))->toBe([['text', EMPTY_DRIVER_SECTION_TEXT]])
            ->and($session->fresh())
            ->current_node_id->toBeNull()
            ->state->toBeNull()
            ->and(AiOperation::query()->where('operation', AiOperationType::SearchQueryExtraction)->count())->toBe(0);
        SearchQueryExtractionAgent::assertNeverPrompted();
    }

    test('кнопка «Я ищу водителя» на показанном экране раздела', function () {
        $scenario = typicalMainDialog();
        $session = branchSessionAt($scenario, 'menu_driver');
        silentNavigator()->shouldNotReceive('route');
        $sent = recordOutbound();

        pressInDialog($session, new InboundMessage(text: 'Я ищу водителя', replyId: 'driver_seek'));

        expectEmptyDriverSectionOnly($sent, $session);
    });

    test('слова в меню, по которым навигатор уверенно ведёт в ветку вместе с текстом', function () {
        $scenario = typicalMainDialog();
        $session = branchSessionAt($scenario, 'main_menu');
        test()->mock(MenuRouter::class)->shouldReceive('route')->once()
            ->andReturn(MenuRoute::toOption(['node_id' => 'menu_driver', 'option_id' => 'driver_seek'], RouteConfidence::High));
        $sent = recordOutbound();

        pressInDialog($session, new InboundMessage(text: 'нужен машинист на экскаватор, Шымкент'));

        expectEmptyDriverSectionOnly($sent, $session);
    });

    test('подтверждённое «Перейти» на предложение навигатора', function () {
        $scenario = typicalMainDialog();
        $session = branchSessionAt($scenario, 'main_menu');
        test()->mock(MenuRouter::class)->shouldReceive('route')->once()
            ->andReturn(MenuRoute::toOption(['node_id' => 'menu_driver', 'option_id' => 'driver_seek'], RouteConfidence::Medium));
        $sent = recordOutbound();

        pressInDialog($session, new InboundMessage(text: 'водитель нужен'));

        expect(outboundTo($sent, $session->contact_id))->toBe([['buttons', 'Похоже, вам нужно «Я ищу водителя». Перейти?']]);

        pressInDialog($session, new InboundMessage(text: 'Перейти', replyId: 'nav_confirm'));

        expectEmptyDriverSectionOnly($sent, $session, before: 1);
    });

    test('кнопка «Я ищу водителя» из более раннего сообщения после закончившегося диалога', function () {
        $scenario = typicalMainDialog();
        $session = branchSessionAt($scenario, 'menu_driver');
        $session->update(['current_node_id' => null]);
        silentNavigator()->shouldNotReceive('route');
        $sent = recordOutbound();

        pressInDialog($session, new InboundMessage(text: 'Я ищу водителя', replyId: 'driver_seek'));

        expectEmptyDriverSectionOnly($sent, $session);
    });

    test('первое сообщение нового диалога, которое навигатор прочёл как поиск водителя', function () {
        $scenario = typicalMainDialog();
        $session = branchSessionAt($scenario, 'main_menu');
        $session->update(['current_node_id' => null]);
        test()->mock(MenuRouter::class)->shouldReceive('route')->once()
            ->andReturn(MenuRoute::toOption(['node_id' => 'menu_driver', 'option_id' => 'driver_seek'], RouteConfidence::High));
        $sent = recordOutbound();

        pressInDialog($session, new InboundMessage(text: 'ищу водителя самосвала'));

        // Знакомому контакту ни приветствия, ни меню — сразу ответ раздела.
        expectEmptyDriverSectionOnly($sent, $session);
    });

    test('следующее сообщение после ответа пустого раздела даёт главное меню, как вернувшемуся', function () {
        $scenario = typicalMainDialog();
        $session = branchSessionAt($scenario, 'menu_driver');
        silentNavigator();
        $sent = recordOutbound();

        pressInDialog($session, new InboundMessage(text: 'Я ищу водителя', replyId: 'driver_seek'));
        pressInDialog($session, new InboundMessage(text: 'а когда появятся?'));

        expect(outboundTo($sent, $session->contact_id))->toBe([
            ['text', EMPTY_DRIVER_SECTION_TEXT],
            ['buttons', MAIN_MENU_TEXT],
        ])
            ->and($session->fresh()->current_node_id)->toBe('main_menu');
    });

    test('появилось хоть одно опубликованное объявление водителя — «Я ищу водителя» приглашает как раньше', function () {
        Listing::factory()->driver()->published()->create();
        $scenario = typicalMainDialog();
        $session = branchSessionAt($scenario, 'menu_driver');
        silentNavigator()->shouldNotReceive('route');
        $sent = recordOutbound();

        pressInDialog($session, new InboundMessage(text: 'Я ищу водителя', replyId: 'driver_seek'));

        expect(outboundTo($sent, $session->contact_id))->toBe([
            ['buttons', 'Какой водитель или машинист нужен и в каком городе? Можно написать или наговорить голосом.'],
        ])
            ->and($session->fresh())
            ->current_node_id->toBe('search_driver')
            ->state->kind->toBe('driver');
    });
});
