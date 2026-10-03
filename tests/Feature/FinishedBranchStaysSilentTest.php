<?php

use App\Ai\Agents\ListingExtractionAgent;
use App\Ai\Agents\SearchQueryExtractionAgent;
use App\Enums\AiOperationType;
use App\Enums\AiOutcome;
use App\Enums\BotScenarioTrigger;
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
use App\Services\DereuMessenger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Ai\Embeddings;
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

    test('кнопка из более раннего сообщения даёт меню один раз — без «кнопка устарела» и без дублей', function (InboundMessage $press) {
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
        'раздел из прежнего главного меню' => [new InboundMessage(text: 'Ремонт спецтехники', replyId: 'kind_repair')],
        'роль с прежнего экрана раздела' => [new InboundMessage(text: 'Я ищу спецтехнику', replyId: 'rent_seek')],
        '«Да, отправить» той же сводки ещё раз' => [new InboundMessage(text: 'Да, отправить', replyId: SupplierListingCollector::BUTTON_SUBMIT)],
    ]);

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
            ['cta', 'Нашлись варианты по запросу «автокран». Смотрите их в каталоге по кнопке ниже — запрос уже подставлен, там же поиск и фильтры. Выберите подходящий — заявка сразу уйдёт поставщику.'],
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
            ['cta', 'Пока по такому запросу пусто. Попробуйте сказать иначе — вид техники и город, например: «кран 25 тонн, Шымкент». Или загляните в каталог по кнопке ниже — там все объявления, база пополняется каждый день.'],
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
});
