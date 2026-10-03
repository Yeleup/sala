<?php

use App\Enums\AiCostStatus;
use App\Enums\BotScenarioTrigger;
use App\Enums\ChannelDirection;
use App\Enums\ChannelMessageStatus;
use App\Enums\ScenarioRunStatus;
use App\Exceptions\SessionWindowClosed;
use App\Models\BotScenario;
use App\Models\BotSession;
use App\Models\ChannelMessage;
use App\Models\Contact;
use App\Models\CustomerRequest;
use App\Models\Listing;
use App\Models\WhatsappTemplate;
use App\Services\Ai\CustomerSearchAssistant;
use App\Services\Bot\InboundMessage;
use App\Services\Bot\MenuRouter;
use App\Services\Bot\NullMenuRouter;
use App\Services\Bot\ScenarioRunner;
use App\Services\Bot\ScenarioRunReplyHandler;
use App\Services\CustomerRequestNotifier;
use App\Services\DereuMessenger;
use App\Services\TemplateFallback;
use App\Services\WhatsappCostEstimator;
use App\Services\WhatsappReplyBuffer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

// Правило задачи #12: в одном ответе бота обычный текст и сразу за ним
// интерактивное сообщение (кнопки, список, кнопка-ссылка) уходят одним
// сообщением — текст в начале тела, через пустую строку. Всё, что сюда не
// подпадает, уходит ровно как раньше.

beforeEach(function () {
    config()->set('services.dereu.external_id', 'org_test');
    config()->set('services.dereu.base_url', 'https://api.dereu.test/api/v1');
    config()->set('services.dereu.webhook_secret', 'whsec_test');
    config()->set('queue.default', 'sync');

    // Навигатор меню ходил бы в модель: в контейнере живой ключ OpenAI.
    app()->bind(MenuRouter::class, NullMenuRouter::class);
});

/**
 * Dereu принимает каждую отправку, кроме тех, что $fails признал упавшими.
 *
 * @param  (Closure(Request): bool)|null  $fails
 */
function fakeDereuForJoining(?Closure $fails = null): void
{
    Http::fake(fn (Request $request) => $fails !== null && $fails($request)
        ? Http::response(['message' => 'upstream error'], 500)
        : Http::response(['id' => (string) Str::uuid(), 'status' => 'queued'], 202));
}

/**
 * Что ушло в Dereu на отправку, по порядку.
 *
 * @return list<array{to: string, type: string, payload: array<string, mixed>}>
 */
function sentToDereu(): array
{
    return Http::recorded(fn (Request $request): bool => str_ends_with($request->url(), '/messages/send'))
        ->map(fn (array $pair): array => [
            'to' => $pair[0]['to'],
            'type' => $pair[0]['type'],
            'payload' => $pair[0]['payload'],
        ])
        ->values()
        ->all();
}

/**
 * Свой экземпляр на каждый вызов — как у сервисов бота, каждый из которых
 * получает собственный мессенджер.
 */
function joinMessenger(): DereuMessenger
{
    return app(DereuMessenger::class);
}

function inBotReply(Closure $reply): mixed
{
    return app(WhatsappReplyBuffer::class)->collect($reply);
}

/** @return list<array{id: string, title: string}> */
function joinMenuButtons(): array
{
    return [
        ['id' => 'rent', 'title' => 'Аренда спецтехники'],
        ['id' => 'repair', 'title' => 'Ремонт спецтехники'],
    ];
}

/** @return list<array{type: string, reply: array{id: string, title: string}}> */
function joinMenuButtonsPayload(): array
{
    return [
        ['type' => 'reply', 'reply' => ['id' => 'rent', 'title' => 'Аренда спецтехники']],
        ['type' => 'reply', 'reply' => ['id' => 'repair', 'title' => 'Ремонт спецтехники']],
    ];
}

function joinApprovedTemplate(): WhatsappTemplate
{
    return WhatsappTemplate::factory()->approved()->create();
}

describe('склейка в одном ответе', function () {
    test('текст и кнопки сразу за ним уходят одним сообщением, кнопки не меняются', function () {
        fakeDereuForJoining();
        connectedDereuCompany();
        $contact = Contact::factory()->withOpenSessionWindow()->create();

        inBotReply(function () use ($contact): void {
            joinMessenger()->sendText($contact, 'Здравствуйте! Это сервис спецтехники.');
            joinMessenger()->sendButtons($contact, 'Что вас интересует?', joinMenuButtons());
        });

        $sent = sentToDereu();
        expect($sent)->toHaveCount(1)
            ->and($sent[0]['type'])->toBe('interactive')
            ->and($sent[0]['payload']['type'])->toBe('button')
            ->and($sent[0]['payload']['body'])->toBe(['text' => "Здравствуйте! Это сервис спецтехники.\n\nЧто вас интересует?"])
            ->and($sent[0]['payload']['action']['buttons'])->toBe(joinMenuButtonsPayload());

        $entry = ChannelMessage::sole();
        expect($entry)
            ->direction->toBe(ChannelDirection::Outbound)
            ->type->toBe('interactive')
            ->text->toBe("Здравствуйте! Это сервис спецтехники.\n\nЧто вас интересует?")
            ->status->toBe(ChannelMessageStatus::Queued)
            ->and($entry->payload)->toBe($sent[0]['payload']);
    });

    test('одно ушедшее сообщение — одно сессионное сообщение в квоте и одна оценка стоимости', function () {
        $this->travelTo('2026-10-05 10:00:00');
        fakeDereuForJoining();
        connectedDereuCompany();
        $contact = Contact::factory()->withOpenSessionWindow()->create();

        inBotReply(function () use ($contact): void {
            joinMessenger()->sendText($contact, 'Черновик сохранили — он ждёт в кабинете.');
            joinMessenger()->sendButtons($contact, 'Что вас интересует?', joinMenuButtons());
        });

        joinMessenger()->sendText($contact, 'Следующее сообщение — уже второе в месяце.');

        $rows = ChannelMessage::query()->orderBy('id')->get();
        expect($rows)->toHaveCount(2)
            ->and($rows[0]->cost_status)->toBe(AiCostStatus::Estimated)
            ->and($rows[0]->pricing_snapshot['position_in_month'])->toBe(1)
            ->and($rows[1]->pricing_snapshot['position_in_month'])->toBe(2)
            ->and(app(WhatsappCostEstimator::class)->sessionMessagesInMonth(now()))->toBe(2);
    });

    test('текст так же встаёт в начало списка и сообщения с кнопкой-ссылкой', function (string $type) {
        fakeDereuForJoining();
        connectedDereuCompany();
        $contact = Contact::factory()->withOpenSessionWindow()->create();

        inBotReply(function () use ($contact, $type): void {
            joinMessenger()->sendText($contact, 'Возвращаемся к анкете.');

            match ($type) {
                'list' => joinMessenger()->sendList($contact, 'Выберите вариант', 'Выбрать', [
                    ['id' => 'a', 'title' => 'Первый'],
                    ['id' => 'b', 'title' => 'Второй'],
                ]),
                'cta_url' => joinMessenger()->sendCtaUrl($contact, 'Выберите вариант', 'Открыть кабинет', 'https://example.test/cabinet'),
            };
        });

        $sent = sentToDereu();
        expect($sent)->toHaveCount(1)
            ->and($sent[0]['payload']['type'])->toBe($type)
            ->and($sent[0]['payload']['body'])->toBe(['text' => "Возвращаемся к анкете.\n\nВыберите вариант"]);
    })->with(['list', 'cta_url']);

    test('из двух текстов подряд в кнопки встаёт только второй, первый уходит сам', function () {
        fakeDereuForJoining();
        connectedDereuCompany();
        $contact = Contact::factory()->withOpenSessionWindow()->create();

        inBotReply(function () use ($contact): void {
            joinMessenger()->sendText($contact, 'Первый текст.');
            joinMessenger()->sendText($contact, 'Второй текст.');
            joinMessenger()->sendButtons($contact, 'Что вас интересует?', joinMenuButtons());
        });

        $sent = sentToDereu();
        expect($sent)->toHaveCount(2)
            ->and($sent[0]['type'])->toBe('text')
            ->and($sent[0]['payload'])->toBe(['body' => 'Первый текст.'])
            ->and($sent[1]['payload']['body'])->toBe(['text' => "Второй текст.\n\nЧто вас интересует?"]);
    });
});

describe('где склейки нет и всё как раньше', function () {
    test('не помещающаяся в лимит склейка уходит двумя сообщениями без обрезки', function () {
        fakeDereuForJoining();
        connectedDereuCompany();
        $contact = Contact::factory()->withOpenSessionWindow()->create();
        // Сам по себе текст помещается в тело кнопок (1024), а вместе с
        // «\n\n» и текстом меню — уже нет.
        $long = str_repeat('Длинный текст. ', 67);

        inBotReply(function () use ($contact, $long): void {
            joinMessenger()->sendText($contact, $long);
            joinMessenger()->sendButtons($contact, 'Что вас интересует?', joinMenuButtons());
        });

        $sent = sentToDereu();
        expect(mb_strlen($long))->toBeLessThanOrEqual(1024)
            ->and($sent)->toHaveCount(2)
            ->and($sent[0]['payload'])->toBe(['body' => $long])
            ->and($sent[1]['payload']['body'])->toBe(['text' => 'Что вас интересует?'])
            ->and($sent[1]['payload']['action']['buttons'])->toBe(joinMenuButtonsPayload());
    });

    test('у списка свой лимит тела — тот же длинный текст в него встаёт', function () {
        fakeDereuForJoining();
        connectedDereuCompany();
        $contact = Contact::factory()->withOpenSessionWindow()->create();
        $long = str_repeat('Длинный текст. ', 67);

        inBotReply(function () use ($contact, $long): void {
            joinMessenger()->sendText($contact, $long);
            joinMessenger()->sendList($contact, 'Выберите вариант', 'Выбрать', [['id' => 'a', 'title' => 'Первый']]);
        });

        expect(sentToDereu())->toHaveCount(1)
            ->and(sentToDereu()[0]['payload']['body'])->toBe(['text' => $long."\n\nВыберите вариант"]);
    });

    test('текст с шаблонным фолбэком не склеивается и несёт фолбэк сам', function () {
        fakeDereuForJoining();
        connectedDereuCompany();
        $template = joinApprovedTemplate();
        $contact = Contact::factory()->withOpenSessionWindow()->create();

        inBotReply(function () use ($contact, $template): void {
            joinMessenger()->sendText($contact, 'Уведомление с планом Б.', new TemplateFallback($template, ['Автокран']));
            joinMessenger()->sendButtons($contact, 'Что вас интересует?', joinMenuButtons());
        });

        $sent = sentToDereu();
        expect($sent)->toHaveCount(2)
            ->and($sent[0]['payload'])->toBe(['body' => 'Уведомление с планом Б.'])
            ->and($sent[1]['payload']['body'])->toBe(['text' => 'Что вас интересует?']);

        [$text, $buttons] = ChannelMessage::query()->orderBy('id')->get()->all();
        expect($text->template_fallback['whatsapp_template_id'])->toBe($template->id)
            ->and($buttons->template_fallback)->toBeNull();
    });

    test('кнопки с шаблонным фолбэком текст в себя не берут — перепосылка шаблоном унесла бы только их', function () {
        fakeDereuForJoining();
        connectedDereuCompany();
        $template = joinApprovedTemplate();
        $contact = Contact::factory()->withOpenSessionWindow()->create();

        inBotReply(function () use ($contact, $template): void {
            joinMessenger()->sendText($contact, 'Обычный текст.');
            joinMessenger()->sendButtons($contact, 'Объявление ещё актуально?', joinMenuButtons(), new TemplateFallback($template, ['Автокран'], ['rent', 'repair']));
        });

        $sent = sentToDereu();
        expect($sent)->toHaveCount(2)
            ->and($sent[0]['payload'])->toBe(['body' => 'Обычный текст.'])
            ->and($sent[1]['payload']['body'])->toBe(['text' => 'Объявление ещё актуально?']);

        [$text, $buttons] = ChannelMessage::query()->orderBy('id')->get()->all();
        expect($text->template_fallback)->toBeNull()
            ->and($buttons->template_fallback['whatsapp_template_id'])->toBe($template->id);
    });

    test('текст без интерактива следом уходит в конце ответа — сам, в своём порядке', function () {
        fakeDereuForJoining();
        connectedDereuCompany();
        $contact = Contact::factory()->withOpenSessionWindow()->create();

        inBotReply(function () use ($contact): void {
            joinMessenger()->sendText($contact, 'Хорошо, остановимся.');

            // Пока ответ идёт, текст придержан: решает следующее сообщение.
            expect(sentToDereu())->toBe([]);
        });

        expect(sentToDereu())->toHaveCount(1)
            ->and(sentToDereu()[0]['payload'])->toBe(['body' => 'Хорошо, остановимся.'])
            ->and(ChannelMessage::sole()->text)->toBe('Хорошо, остановимся.');
    });

    test('шаблон после текста не склеивается: текст уходит первым', function () {
        fakeDereuForJoining();
        connectedDereuCompany();
        $template = joinApprovedTemplate();
        $contact = Contact::factory()->withOpenSessionWindow()->create();

        inBotReply(function () use ($contact, $template): void {
            joinMessenger()->sendText($contact, 'Сначала текст.');
            joinMessenger()->sendTemplate($contact, $template, ['Автокран']);
        });

        expect(array_column(sentToDereu(), 'type'))->toBe(['text', 'template'])
            ->and(sentToDereu()[0]['payload'])->toBe(['body' => 'Сначала текст.']);
    });

    test('два интерактивных подряд остаются двумя сообщениями', function () {
        fakeDereuForJoining();
        connectedDereuCompany();
        $contact = Contact::factory()->withOpenSessionWindow()->create();

        inBotReply(function () use ($contact): void {
            joinMessenger()->sendButtons($contact, 'Нашлись варианты.', [['id' => 'menu', 'title' => 'В меню']]);
            joinMessenger()->sendCtaUrl($contact, 'Все варианты в каталоге.', 'Все варианты', 'https://example.test/catalog');
        });

        $sent = sentToDereu();
        expect($sent)->toHaveCount(2)
            ->and($sent[0]['payload']['body'])->toBe(['text' => 'Нашлись варианты.'])
            ->and($sent[1]['payload']['body'])->toBe(['text' => 'Все варианты в каталоге.']);
    });

    test('текст одному контакту не встаёт в кнопки другому', function () {
        fakeDereuForJoining();
        connectedDereuCompany();
        $customer = Contact::factory()->withOpenSessionWindow()->create(['phone' => '77010000001']);
        $supplier = Contact::factory()->withOpenSessionWindow()->create(['phone' => '77010000002']);

        inBotReply(function () use ($customer, $supplier): void {
            joinMessenger()->sendText($customer, 'Заказчику.');
            joinMessenger()->sendButtons($supplier, 'Поставщику.', joinMenuButtons());
        });

        $sent = sentToDereu();
        expect($sent)->toHaveCount(2)
            ->and($sent[0]['to'])->toBe('+77010000001')
            ->and($sent[0]['payload'])->toBe(['body' => 'Заказчику.'])
            ->and($sent[1]['to'])->toBe('+77010000002')
            ->and($sent[1]['payload']['body'])->toBe(['text' => 'Поставщику.']);
    });

    test('вне ответа бота отправки уходят сразу и не склеиваются', function () {
        fakeDereuForJoining();
        connectedDereuCompany();
        $contact = Contact::factory()->withOpenSessionWindow()->create();

        joinMessenger()->sendText($contact, 'Уведомление.');
        expect(sentToDereu())->toHaveCount(1);

        joinMessenger()->sendButtons($contact, 'Что вас интересует?', joinMenuButtons());

        expect(sentToDereu())->toHaveCount(2)
            ->and(sentToDereu()[1]['payload']['body'])->toBe(['text' => 'Что вас интересует?']);
    });

    test('после конца ответа ничего не придерживается — следующий текст уходит сразу', function () {
        fakeDereuForJoining();
        connectedDereuCompany();
        $contact = Contact::factory()->withOpenSessionWindow()->create();

        inBotReply(fn () => joinMessenger()->sendText($contact, 'В ответе.'));
        joinMessenger()->sendText($contact, 'После ответа.');

        expect(app(WhatsappReplyBuffer::class)->isCollecting())->toBeFalse()
            ->and(array_column(array_column(sentToDereu(), 'payload'), 'body'))->toBe(['В ответе.', 'После ответа.']);
    });

    test('закрытое окно по-прежнему отказывает тексту сразу, и в ответе тоже', function () {
        fakeDereuForJoining();
        connectedDereuCompany();
        $contact = Contact::factory()->withClosedSessionWindow()->create();

        inBotReply(function () use ($contact): void {
            expect(fn () => joinMessenger()->sendText($contact, 'Не дойдёт.'))
                ->toThrow(SessionWindowClosed::class);
        });

        expect(sentToDereu())->toBe([])
            ->and(ChannelMessage::count())->toBe(0);
    });
});

describe('сбои внутри ответа', function () {
    test('упавший ответ всё равно доставляет придержанный текст, наружу выходит исходная ошибка', function () {
        fakeDereuForJoining();
        connectedDereuCompany();
        $contact = Contact::factory()->withOpenSessionWindow()->create();

        expect(fn () => inBotReply(function () use ($contact): void {
            joinMessenger()->sendText($contact, 'Черновик сохранили — он ждёт в кабинете.');

            throw new RuntimeException('AI provider is down');
        }))->toThrow(RuntimeException::class, 'AI provider is down');

        expect(sentToDereu())->toHaveCount(1)
            ->and(sentToDereu()[0]['payload'])->toBe(['body' => 'Черновик сохранили — он ждёт в кабинете.'])
            ->and(app(WhatsappReplyBuffer::class)->isCollecting())->toBeFalse();
    });

    test('сбой отправки придержанного текста не подменяет ошибку, уронившую ответ', function () {
        fakeDereuForJoining(fn (): bool => true);
        connectedDereuCompany();
        $contact = Contact::factory()->withOpenSessionWindow()->create();
        Log::spy();

        expect(fn () => inBotReply(function () use ($contact): void {
            joinMessenger()->sendText($contact, 'Не уйдёт.');

            throw new RuntimeException('AI provider is down');
        }))->toThrow(RuntimeException::class, 'AI provider is down');

        // Попытка была и оставила след в журнале как упавшая отправка.
        expect(ChannelMessage::sole())
            ->status->toBe(ChannelMessageStatus::Failed)
            ->text->toBe('Не уйдёт.');

        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message): bool => str_contains($message, 'held text of a failed bot reply'))
            ->once();
    });

    test('склеенное сообщение, отвергнутое Dereu, — одна строка «ошибка», текст второй раз не уходит', function () {
        fakeDereuForJoining(fn (): bool => true);
        connectedDereuCompany();
        $contact = Contact::factory()->withOpenSessionWindow()->create();

        expect(fn () => inBotReply(function () use ($contact): void {
            joinMessenger()->sendText($contact, 'Приветствие.');
            joinMessenger()->sendButtons($contact, 'Что вас интересует?', joinMenuButtons());
        }))->toThrow(RequestException::class);

        expect(sentToDereu())->toHaveCount(1)
            ->and(ChannelMessage::sole())
            ->status->toBe(ChannelMessageStatus::Failed)
            ->text->toBe("Приветствие.\n\nЧто вас интересует?");
    });

    test('джоба, выполненная синхронно внутри ответа, его не сбрасывает и не опустошает', function () {
        fakeDereuForJoining();
        connectedDereuCompany();
        $contact = Contact::factory()->withOpenSessionWindow()->create();

        inBotReply(function () use ($contact): void {
            joinMessenger()->sendText($contact, 'Приветствие.');

            // В тестах очередь синхронная: вложенная джоба (например,
            // эмбеддинг объявления) исполняется прямо посреди ответа, а со
            // своим ответом внутри — тем более не должна его закрыть.
            dispatch(function (): void {
                app(WhatsappReplyBuffer::class)->collect(fn () => null);
            })->onConnection('sync');

            joinMessenger()->sendButtons($contact, 'Что вас интересует?', joinMenuButtons());
        });

        expect(sentToDereu())->toHaveCount(1)
            ->and(sentToDereu()[0]['payload']['body'])->toBe(['text' => "Приветствие.\n\nЧто вас интересует?"]);
    });
});

describe('сквозной путь входящего сообщения', function () {
    /**
     * Подписанное событие message_received — тот же путь, что у живого
     * сообщения: вебхук → джоба → движок.
     */
    function joinSignedInboundMessage(string $from, string $text): void
    {
        $payload = [
            'event' => 'message_received',
            'event_id' => (string) Str::ulid(),
            'company_id' => 'co_join',
            'phone_number_id' => '1234567890',
            'from' => $from,
            'wamid' => 'wamid.'.Str::random(12),
            'type' => 'text',
            'payload' => ['body' => $text],
            'timestamp' => now()->subSeconds(5)->timestamp,
        ];

        test()->postJson(route('webhooks.dereu'), $payload, [
            'X-Dereu-Signature' => 'sha256='.hash_hmac('sha256', json_encode($payload), 'whsec_test'),
        ])->assertNoContent();
    }

    test('приветствие (текстовый блок) и главное меню после него приходят одним сообщением', function () {
        fakeDereuForJoining();
        connectedDereuCompany(['dereu_company_id' => 'co_join', 'phone_number_id' => '1234567890']);
        BotScenario::factory()->published([
            'nodes' => [
                ['id' => 'start', 'type' => 'start'],
                ['id' => 'greeting', 'type' => 'text', 'text' => 'Здравствуйте! Это сервис спецтехники.'],
                ['id' => 'main_menu', 'type' => 'buttons', 'text' => 'Что вас интересует?', 'options' => [
                    ['id' => 'rent', 'title' => 'Аренда спецтехники'],
                    ['id' => 'repair', 'title' => 'Ремонт спецтехники'],
                ]],
                ['id' => 'rent_branch', 'type' => 'text', 'text' => 'Аренда'],
                ['id' => 'repair_branch', 'type' => 'text', 'text' => 'Ремонт'],
            ],
            'edges' => [
                ['from' => 'start', 'output' => 'continue', 'to' => 'greeting'],
                ['from' => 'greeting', 'output' => 'continue', 'to' => 'main_menu'],
                ['from' => 'main_menu', 'output' => 'option:rent', 'to' => 'rent_branch'],
                ['from' => 'main_menu', 'output' => 'option:repair', 'to' => 'repair_branch'],
            ],
        ])->create();

        joinSignedInboundMessage('77015550101', 'Здравствуйте');

        $sent = sentToDereu();
        expect($sent)->toHaveCount(1)
            ->and($sent[0]['to'])->toBe('+77015550101')
            ->and($sent[0]['type'])->toBe('interactive')
            ->and($sent[0]['payload']['body'])->toBe(['text' => "Здравствуйте! Это сервис спецтехники.\n\nЧто вас интересует?"])
            ->and($sent[0]['payload']['action']['buttons'])->toBe(joinMenuButtonsPayload());

        $outbound = ChannelMessage::query()->where('direction', ChannelDirection::Outbound)->get();
        expect($outbound)->toHaveCount(1)
            ->and($outbound->sole()->text)->toBe("Здравствуйте! Это сервис спецтехники.\n\nЧто вас интересует?");

        // Кнопки работают как раньше: выбор ведёт по своему выходу.
        joinSignedInboundMessage('77015550101', 'Ремонт спецтехники');

        expect(ChannelMessage::query()->where('direction', ChannelDirection::Outbound)->latest('id')->first()->text)
            ->toBe('Ремонт');
    });
});

describe('запуски сценариев и уведомления внутри ответа', function () {
    /**
     * Запуск, ждущий ответа на «Понятно»; дальше — текст и, если задан,
     * второй вопрос.
     */
    function joinRunScenario(bool $withSecondQuestion): BotScenario
    {
        $nodes = [
            ['id' => 'start', 'type' => 'start'],
            ['id' => 'ask', 'type' => 'message', 'text' => 'Объявление ещё актуально?', 'channel' => 'session',
                'options' => [['id' => 'ok', 'title' => 'Понятно']]],
            ['id' => 'note', 'type' => 'text', 'text' => 'Принято.'],
            ['id' => 'end', 'type' => 'end'],
        ];
        $edges = [
            ['from' => 'start', 'output' => 'continue', 'to' => 'ask'],
            ['from' => 'ask', 'output' => 'option:ok', 'to' => 'note'],
        ];

        if ($withSecondQuestion) {
            $nodes[] = ['id' => 'ask_more', 'type' => 'message', 'text' => 'Ещё вопрос?', 'channel' => 'session',
                'options' => [['id' => 'yes', 'title' => 'Да']]];
            $edges[] = ['from' => 'note', 'output' => 'continue', 'to' => 'ask_more'];
            $edges[] = ['from' => 'ask_more', 'output' => 'option:yes', 'to' => 'end'];
        } else {
            $edges[] = ['from' => 'note', 'output' => 'continue', 'to' => 'end'];
        }

        return BotScenario::factory()
            ->trigger(BotScenarioTrigger::ListingExpiring)
            ->published(['nodes' => $nodes, 'edges' => $edges])
            ->create();
    }

    test('текстовый блок запуска и его следующий вопрос в ответе на кнопку уходят одним сообщением', function () {
        fakeDereuForJoining();
        connectedDereuCompany();
        $supplier = Contact::factory()->withOpenSessionWindow()->create();
        $listing = Listing::factory()->published()->for($supplier, 'supplier')->create();
        $run = app(ScenarioRunner::class)->launch(joinRunScenario(withSecondQuestion: true), $supplier, $listing);

        inBotReply(fn () => app(ScenarioRunReplyHandler::class)->handle(
            $supplier,
            new InboundMessage(replyId: "flow:{$run->token}:ok"),
        ));

        $sent = sentToDereu();
        expect($sent)->toHaveCount(2)
            ->and($sent[1]['payload']['body'])->toBe(['text' => "Принято.\n\nЕщё вопрос?"])
            ->and($sent[1]['payload']['action']['buttons'][0]['reply']['id'])->toBe("flow:{$run->token}:yes")
            ->and($run->refresh()->current_node_id)->toBe('ask_more');
    });

    test('свой несостоявшийся текст запуск записывает на себя, а ответ не роняет', function () {
        $failing = false;
        fakeDereuForJoining(function () use (&$failing): bool {
            return $failing;
        });
        connectedDereuCompany();
        $supplier = Contact::factory()->withOpenSessionWindow()->create();
        $listing = Listing::factory()->published()->for($supplier, 'supplier')->create();
        $run = app(ScenarioRunner::class)->launch(joinRunScenario(withSecondQuestion: false), $supplier, $listing);
        $failing = true;

        // Раньше отказ текста ловил сам запуск: «ошибка отправки», а не
        // «завершён», и входящее сообщение обработано без повтора.
        inBotReply(fn () => app(ScenarioRunReplyHandler::class)->handle(
            $supplier,
            new InboundMessage(replyId: "flow:{$run->token}:ok"),
        ));

        expect($run->refresh()->status)->toBe(ScenarioRunStatus::Failed);
    });

    test('придержанный текст заказчика не выдаётся за недошедшее уведомление поставщику', function () {
        $customer = Contact::factory()->withOpenSessionWindow()->create(['phone' => '77010000001']);
        $supplier = Contact::factory()->withOpenSessionWindow()->create(['phone' => '77010000002']);
        fakeDereuForJoining(fn (Request $request): bool => $request['to'] === '+77010000001');
        connectedDereuCompany();
        $request = CustomerRequest::factory()->create([
            'contact_id' => $customer->id,
            'listing_id' => Listing::factory()->published()->for($supplier, 'supplier')->create()->id,
            'query_text' => 'нужен кран',
        ]);

        // Раньше упавший текст заказчику бросал ошибку сам — до уведомления;
        // уведомитель не должен проглотить её как «поставщик недоступен».
        expect(fn () => inBotReply(function () use ($customer, $request): void {
            joinMessenger()->sendText($customer, 'Текст заказчику.');
            app(CustomerRequestNotifier::class)->notifySupplier($request);
        }))->toThrow(RequestException::class);

        expect(collect(sentToDereu())->pluck('to')->all())->toBe(['+77010000001']);
    });
});

describe('ответы ИИ-ассистента', function () {
    /**
     * Поиск заказчика, ждущий выбора строки прежней чат-выдачи, чей
     * вариант уже сняли с публикации.
     */
    function joinStaleRowSearch(Contact $customer, Listing $listing, int $attempts): BotSession
    {
        return BotSession::factory()->waitingAt('search')->create([
            'contact_id' => $customer->id,
            'state' => [
                'phase' => 'choosing', 'attempts' => $attempts, 'clarifications' => 0,
                'transcript' => [], 'query' => 'кран', 'offered' => [$listing->id],
            ],
        ]);
    }

    test('реплика ассистента и его сообщение с кнопкой следом приходят одним сообщением', function () {
        fakeDereuForJoining();
        connectedDereuCompany();
        $customer = Contact::factory()->withOpenSessionWindow()->create();
        $listing = Listing::factory()->expired()->create(['category_id' => categoryNamed('Автокран')->id]);
        $session = joinStaleRowSearch($customer, $listing, attempts: 0);

        inBotReply(fn () => app(CustomerSearchAssistant::class)->resume(
            $session,
            ['id' => 'search', 'type' => 'ai', 'task' => 'customer_search'],
            new InboundMessage(replyId: "listing:{$listing->id}"),
        ));

        // Тупик поиска — как и раньше: кнопка «В меню» и отдельным
        // сообщением ссылка в каталог; реплика про устаревший вариант
        // встала в начало первого из них.
        $sent = sentToDereu();
        expect($sent)->toHaveCount(2)
            ->and($sent[0]['payload']['body']['text'])->toStartWith("Этот вариант уже сняли с публикации. Сейчас поищем свежие.\n\n")
            ->and($sent[0]['payload']['action']['buttons'][0]['reply']['id'])->toBe(CustomerSearchAssistant::BUTTON_MENU)
            ->and($sent[1]['payload']['type'])->toBe('cta_url')
            ->and($sent[1]['payload']['body'])->toBe(['text' => 'Или загляните в каталог — там все объявления, база пополняется каждый день.']);
    });

    test('ссылка в каталог, чей сбой глотается, не берёт в себя придержанный текст', function () {
        fakeDereuForJoining(fn (): bool => true);
        connectedDereuCompany();
        $customer = Contact::factory()->withOpenSessionWindow()->create();
        $listing = Listing::factory()->expired()->create(['category_id' => categoryNamed('Автокран')->id]);
        $session = joinStaleRowSearch($customer, $listing, attempts: 3);

        // Внутри ссылки сбой текста был бы проглочен вместе с ней; раньше
        // упавший текст ронял ход, и сообщение обрабатывалось повторно.
        expect(fn () => inBotReply(fn () => app(CustomerSearchAssistant::class)->resume(
            $session,
            ['id' => 'search', 'type' => 'ai', 'task' => 'customer_search'],
            new InboundMessage(replyId: "listing:{$listing->id}"),
        )))->toThrow(RequestException::class);

        expect(sentToDereu())->toHaveCount(1)
            ->and(sentToDereu()[0]['payload'])->toBe(['body' => 'Этот вариант уже сняли с публикации. Сейчас поищем свежие.']);
    });
});
