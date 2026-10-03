<?php

use App\Ai\Agents\ListingExtractionAgent;
use App\Enums\AiCostStatus;
use App\Enums\BotScenarioTrigger;
use App\Enums\ChannelDirection;
use App\Enums\ChannelMessageStatus;
use App\Enums\ListingKind;
use App\Enums\ScenarioRunStatus;
use App\Exceptions\SessionWindowClosed;
use App\Jobs\ProcessDereuWebhookEvent;
use App\Models\BotScenario;
use App\Models\BotSession;
use App\Models\ChannelMessage;
use App\Models\Contact;
use App\Models\CustomerRequest;
use App\Models\DereuCompany;
use App\Models\DereuWebhookEvent;
use App\Models\Listing;
use App\Models\ScenarioRun;
use App\Models\WhatsappTemplate;
use App\Services\Ai\CtaLinkBuilder;
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

/**
 * Ответ бота человеку $to — то, что делает джоба входящего сообщения.
 */
function inBotReply(Contact $to, Closure $reply): mixed
{
    return app(WhatsappReplyBuffer::class)->collect($to, $reply);
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

        inBotReply($contact, function () use ($contact): void {
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

        inBotReply($contact, function () use ($contact): void {
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

        inBotReply($contact, function () use ($contact, $type): void {
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

        inBotReply($contact, function () use ($contact): void {
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

        inBotReply($contact, function () use ($contact, $long): void {
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

        inBotReply($contact, function () use ($contact, $long): void {
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

        inBotReply($contact, function () use ($contact, $template): void {
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

        inBotReply($contact, function () use ($contact, $template): void {
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

        inBotReply($contact, function () use ($contact): void {
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

        inBotReply($contact, function () use ($contact, $template): void {
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

        inBotReply($contact, function () use ($contact): void {
            joinMessenger()->sendButtons($contact, 'Нашлись варианты.', [['id' => 'menu', 'title' => 'В меню']]);
            joinMessenger()->sendCtaUrl($contact, 'Все варианты в каталоге.', 'Все варианты', 'https://example.test/catalog');
        });

        $sent = sentToDereu();
        expect($sent)->toHaveCount(2)
            ->and($sent[0]['payload']['body'])->toBe(['text' => 'Нашлись варианты.'])
            ->and($sent[1]['payload']['body'])->toBe(['text' => 'Все варианты в каталоге.']);
    });

    test('сообщение другому человеку — не часть ответа: уходит сразу, а текст ответа ждёт своих кнопок', function () {
        fakeDereuForJoining();
        connectedDereuCompany();
        $customer = Contact::factory()->withOpenSessionWindow()->create(['phone' => '77010000001']);
        $supplier = Contact::factory()->withOpenSessionWindow()->create(['phone' => '77010000002']);

        inBotReply($customer, function () use ($customer, $supplier): void {
            joinMessenger()->sendText($customer, 'Заказчику.');
            joinMessenger()->sendText($supplier, 'Поставщику — сразу.');
            joinMessenger()->sendButtons($supplier, 'Поставщику — кнопки.', joinMenuButtons());
            joinMessenger()->sendButtons($customer, 'Что дальше?', joinMenuButtons());
        });

        $sent = sentToDereu();
        expect($sent)->toHaveCount(3)
            ->and($sent[0]['to'])->toBe('+77010000002')
            ->and($sent[0]['payload'])->toBe(['body' => 'Поставщику — сразу.'])
            ->and($sent[1]['to'])->toBe('+77010000002')
            ->and($sent[1]['payload']['body'])->toBe(['text' => 'Поставщику — кнопки.'])
            ->and($sent[2]['to'])->toBe('+77010000001')
            ->and($sent[2]['payload']['body'])->toBe(['text' => "Заказчику.\n\nЧто дальше?"]);
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

        inBotReply($contact, fn () => joinMessenger()->sendText($contact, 'В ответе.'));
        joinMessenger()->sendText($contact, 'После ответа.');

        expect(app(WhatsappReplyBuffer::class)->isCollectingFor($contact))->toBeFalse()
            ->and(array_column(array_column(sentToDereu(), 'payload'), 'body'))->toBe(['В ответе.', 'После ответа.']);
    });

    test('закрытое окно по-прежнему отказывает тексту сразу, и в ответе тоже', function () {
        fakeDereuForJoining();
        connectedDereuCompany();
        $contact = Contact::factory()->withClosedSessionWindow()->create();

        inBotReply($contact, function () use ($contact): void {
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

        expect(fn () => inBotReply($contact, function () use ($contact): void {
            joinMessenger()->sendText($contact, 'Черновик сохранили — он ждёт в кабинете.');

            throw new RuntimeException('AI provider is down');
        }))->toThrow(RuntimeException::class, 'AI provider is down');

        expect(sentToDereu())->toHaveCount(1)
            ->and(sentToDereu()[0]['payload'])->toBe(['body' => 'Черновик сохранили — он ждёт в кабинете.'])
            ->and(app(WhatsappReplyBuffer::class)->isCollectingFor($contact))->toBeFalse();
    });

    test('сбой отправки придержанного текста не подменяет ошибку, уронившую ответ', function () {
        fakeDereuForJoining(fn (): bool => true);
        connectedDereuCompany();
        $contact = Contact::factory()->withOpenSessionWindow()->create();
        Log::spy();

        expect(fn () => inBotReply($contact, function () use ($contact): void {
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

    test('склеенное сообщение, отвергнутое Dereu, не уносит текст: он пробует уйти сам, наружу — исходная ошибка', function () {
        fakeDereuForJoining(fn (Request $request): bool => $request['type'] === 'interactive');
        connectedDereuCompany();
        $contact = Contact::factory()->withOpenSessionWindow()->create();

        // Раньше текст уходил отдельным сообщением и до отказа кнопок
        // доходил; теперь он пробует уйти сам, когда склейка не прошла.
        expect(fn () => inBotReply($contact, function () use ($contact): void {
            joinMessenger()->sendText($contact, 'Приветствие.');
            joinMessenger()->sendButtons($contact, 'Что вас интересует?', joinMenuButtons());
        }))->toThrow(RequestException::class);

        $sent = sentToDereu();
        expect($sent)->toHaveCount(2)
            ->and($sent[0]['payload']['body'])->toBe(['text' => "Приветствие.\n\nЧто вас интересует?"])
            ->and($sent[1]['payload'])->toBe(['body' => 'Приветствие.']);

        $rows = ChannelMessage::query()->orderBy('id')->get();
        expect($rows->pluck('status')->all())->toBe([ChannelMessageStatus::Failed, ChannelMessageStatus::Queued])
            ->and($rows[1]->text)->toBe('Приветствие.');
    });

    test('запись состояния диалога не обгоняет придержанный текст: он уходит перед ней', function () {
        fakeDereuForJoining();
        connectedDereuCompany();
        $contact = Contact::factory()->withOpenSessionWindow()->create();
        $session = BotSession::factory()->waitingAt('menu')->create(['contact_id' => $contact->id]);

        inBotReply($contact, function () use ($contact, $session): void {
            joinMessenger()->sendText($contact, 'Хорошо, остановимся.');

            // Журналы — что сказано и сколько стоило ИИ — текст не торопят.
            ChannelMessage::factory()->create(['contact_id' => $contact->id]);
            expect(sentToDereu())->toBe([]);

            $session->update(['current_node_id' => null]);
            expect(sentToDereu())->toHaveCount(1);
        });

        expect(sentToDereu())->toHaveCount(1);
    });

    test('не ушедший текст останавливает ход до записи состояния — как раньше его собственная отправка', function () {
        fakeDereuForJoining(fn (): bool => true);
        connectedDereuCompany();
        $contact = Contact::factory()->withOpenSessionWindow()->create();
        $session = BotSession::factory()->waitingAt('menu')->create(['contact_id' => $contact->id]);

        expect(fn () => inBotReply($contact, function () use ($contact, $session): void {
            joinMessenger()->sendText($contact, 'Хорошо, остановимся.');
            $session->update(['current_node_id' => null]);
        }))->toThrow(RequestException::class);

        expect($session->fresh()->current_node_id)->toBe('menu');
    });

    test('джоба, выполненная синхронно внутри ответа, его не сбрасывает и не опустошает', function () {
        fakeDereuForJoining();
        connectedDereuCompany();
        $contact = Contact::factory()->withOpenSessionWindow()->create();

        inBotReply($contact, function () use ($contact): void {
            joinMessenger()->sendText($contact, 'Приветствие.');

            // В тестах очередь синхронная: вложенная джоба (например,
            // эмбеддинг объявления) исполняется прямо посреди ответа, а со
            // своим ответом внутри — тем более не должна его закрыть.
            dispatch(function (): void {
                app(WhatsappReplyBuffer::class)->collect(Contact::query()->firstOrFail(), fn () => null);
            })->onConnection('sync');

            joinMessenger()->sendButtons($contact, 'Что вас интересует?', joinMenuButtons());
        });

        expect(sentToDereu())->toHaveCount(1)
            ->and(sentToDereu()[0]['payload']['body'])->toBe(['text' => "Приветствие.\n\nЧто вас интересует?"]);
    });
});

/**
 * Сохранённое входящее событие — его обрабатывает джоба, и его же она
 * обрабатывает при повторе из очереди.
 *
 * @param  array<string, mixed>  $message  type и payload сообщения WhatsApp
 */
function joinInboundEvent(Contact $from, array $message): DereuWebhookEvent
{
    return DereuWebhookEvent::factory()->create([
        'company_id' => DereuCompany::current()?->dereu_company_id,
        'payload' => [
            'event' => 'message_received',
            'from' => $from->phone,
            'timestamp' => now()->subSeconds(5)->timestamp,
            ...$message,
        ],
    ]);
}

function joinPress(string $id, string $title): array
{
    return ['type' => 'interactive', 'payload' => ['button_reply' => ['id' => $id, 'title' => $title]]];
}

function runJoinJob(DereuWebhookEvent $event): void
{
    app()->call([new ProcessDereuWebhookEvent($event), 'handle']);
}

/**
 * Старт → приветствие → меню двух разделов; «Ремонт» — текст и конец ветки.
 */
function joinMenuScenario(): BotScenario
{
    return BotScenario::factory()->published([
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
}

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
        joinMenuScenario();

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
    test('повтор упавшего хода выдаёт ту же завершающую реплику ветки, а не новое меню', function () {
        $failing = true;
        fakeDereuForJoining(function () use (&$failing): bool {
            return $failing;
        });
        connectedDereuCompany();
        $scenario = joinMenuScenario();
        $contact = Contact::factory()->withOpenSessionWindow()->create(['phone' => '77015550102']);
        $session = BotSession::factory()->waitingAt('main_menu')->create([
            'contact_id' => $contact->id,
            'bot_scenario_id' => $scenario->id,
            'scenario_version' => $scenario->published_version,
        ]);
        $event = joinInboundEvent($contact, joinPress('repair', 'Ремонт спецтехники'));

        // Ветка «Текст → конец»: Dereu отвечает 500 на её текст. Ход
        // обрывается раньше, чем диалог записан завершённым.
        expect(fn () => runJoinJob($event))->toThrow(RequestException::class);
        expect($session->fresh()->current_node_id)->toBe('main_menu')
            ->and($event->fresh()->processed_at)->toBeNull();

        // Очередь повторяет то же событие — и человек получает ту же реплику.
        $failing = false;
        runJoinJob($event);

        $sent = sentToDereu();
        expect($sent)->toHaveCount(2)
            ->and($sent[0]['payload'])->toBe(['body' => 'Ремонт'])
            ->and($sent[1]['payload'])->toBe(['body' => 'Ремонт'])
            ->and($session->fresh()->current_node_id)->toBeNull()
            ->and($event->fresh()->processed_at)->not->toBeNull();
    });

    test('повтор первого сообщения после сбоя снова приносит приветствие вместе с меню', function () {
        $failing = true;
        fakeDereuForJoining(function () use (&$failing): bool {
            return $failing;
        });
        connectedDereuCompany();
        joinMenuScenario();
        $contact = Contact::factory()->withOpenSessionWindow()->create(['phone' => '77015550103']);
        $event = joinInboundEvent($contact, ['type' => 'text', 'payload' => ['body' => 'Здравствуйте']]);

        expect(fn () => runJoinJob($event))->toThrow(RequestException::class);

        // Диалог не числится начатым: повтор начинает его заново.
        expect(BotSession::sole()->current_node_id)->toBeNull();

        $failing = false;
        runJoinJob($event);

        $delivered = collect(sentToDereu())->last();
        expect($delivered['payload']['body'])->toBe(['text' => "Здравствуйте! Это сервис спецтехники.\n\nЧто вас интересует?"])
            ->and($delivered['payload']['action']['buttons'])->toBe(joinMenuButtonsPayload())
            ->and(BotSession::sole()->current_node_id)->toBe('main_menu');
    });

    test('выход из анкеты в меню: строка о черновике и меню одним сообщением, и повтор после сбоя приносит то же', function () {
        ListingExtractionAgent::fake([['user_intent' => 'menu'], ['user_intent' => 'menu']]);
        $failing = true;
        fakeDereuForJoining(function () use (&$failing): bool {
            return $failing;
        });
        connectedDereuCompany();
        test()->artisan('bot:install-default-scenario', ['--only' => BotScenarioTrigger::InboundMessage->value])->assertSuccessful();
        $scenario = BotScenario::main();
        $definition = $scenario->publishedDefinition();
        $contact = Contact::factory()->withOpenSessionWindow()->create(['phone' => '77015550104']);
        $draft = Listing::factory()->create(['contact_id' => $contact->id]);
        $session = BotSession::factory()->waitingAt('collect_rental')->create([
            'contact_id' => $contact->id,
            'bot_scenario_id' => $scenario->id,
            'scenario_version' => $scenario->published_version,
            'current_node_fingerprint' => $definition->nodeFingerprint($definition->node('collect_rental')),
            'last_dialog_ended_at' => now()->subMinutes(5),
            'state' => [
                'kind' => 'rental', 'phase' => 'confirming', 'attempts' => 0,
                'transcript' => ['Сдаю трактор в Шымкенте, 10000 тг/час'],
                'fields' => ['description' => 'Трактор в аренду'],
                'draft_id' => $draft->id,
            ],
        ]);
        $event = joinInboundEvent($contact, ['type' => 'text', 'payload' => ['body' => 'покажите другие разделы']]);

        // Сбой отправки: анкета не отпущена — её память и шаг на месте.
        expect(fn () => runJoinJob($event))->toThrow(RequestException::class);
        expect($session->fresh())
            ->current_node_id->toBe('collect_rental')
            ->and($session->fresh()->state['draft_id'])->toBe($draft->id);

        $failing = false;
        runJoinJob($event);

        $delivered = collect(sentToDereu())->last();
        expect($delivered['payload']['body'])->toBe(['text' => "Черновик сохранили — он ждёт в кабинете.\n\nЧто вас интересует?"])
            ->and($session->fresh())
            ->current_node_id->toBe('main_menu')
            ->state->toBeNull();
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

        inBotReply($supplier, fn () => app(ScenarioRunReplyHandler::class)->handle(
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
        inBotReply($supplier, fn () => app(ScenarioRunReplyHandler::class)->handle(
            $supplier,
            new InboundMessage(replyId: "flow:{$run->token}:ok"),
        ));

        expect($run->refresh()->status)->toBe(ScenarioRunStatus::Failed);
    });

    test('текст запуска перед упавшим блоком уходит в пределах запуска: запуск — «ошибка», ответ не повторяется, чужая отправка его не берёт', function () {
        $failing = false;
        fakeDereuForJoining(function () use (&$failing): bool {
            return $failing;
        });
        connectedDereuCompany();
        $supplier = Contact::factory()->withOpenSessionWindow()->create();
        $listing = Listing::factory()->published()->for($supplier, 'supplier')->create();
        $scenario = BotScenario::factory()
            ->trigger(BotScenarioTrigger::ListingExpiring)
            ->published([
                'nodes' => [
                    ['id' => 'start', 'type' => 'start'],
                    ['id' => 'ask', 'type' => 'message', 'text' => 'Объявление ещё актуально?', 'channel' => 'session',
                        'options' => [['id' => 'ok', 'title' => 'Понятно']]],
                    ['id' => 'note', 'type' => 'text', 'text' => 'Принято.'],
                    // Шаблона нет в реестре — блок падает, ничего не отправив.
                    ['id' => 'broken', 'type' => 'message', 'channel' => 'adaptive', 'template_name' => 'missing_template',
                        'options' => [['id' => 'yes', 'title' => 'Да']]],
                ],
                'edges' => [
                    ['from' => 'start', 'output' => 'continue', 'to' => 'ask'],
                    ['from' => 'ask', 'output' => 'option:ok', 'to' => 'note'],
                    ['from' => 'note', 'output' => 'continue', 'to' => 'broken'],
                ],
            ])
            ->create();
        $run = app(ScenarioRunner::class)->launch($scenario, $supplier, $listing);
        $failing = true;

        inBotReply($supplier, function () use ($supplier, $run, &$failing): void {
            app(ScenarioRunReplyHandler::class)->handle($supplier, new InboundMessage(replyId: "flow:{$run->token}:ok"));

            expect(app(WhatsappReplyBuffer::class)->heldTextFor($supplier))->toBeNull();

            $failing = false;
            joinMessenger()->sendButtons($supplier, 'Что вас интересует?', joinMenuButtons());
        });

        $sent = sentToDereu();
        expect($run->refresh()->status)->toBe(ScenarioRunStatus::Failed)
            ->and($sent)->toHaveCount(3)
            ->and($sent[1]['payload'])->toBe(['body' => 'Принято.'])
            ->and($sent[2]['payload']['body'])->toBe(['text' => 'Что вас интересует?']);
    });

    test('уведомление поставщику по выбору заказчика уходит сразу и не склеивается, а ответ поставщика на его кнопку склеивается', function () {
        fakeDereuForJoining();
        connectedDereuCompany();
        $main = BotScenario::factory()->published([
            'nodes' => [
                ['id' => 'start', 'type' => 'start'],
                ['id' => 'search', 'type' => 'ai', 'task' => 'customer_search'],
            ],
            'edges' => [['from' => 'start', 'output' => 'continue', 'to' => 'search']],
        ])->create();
        BotScenario::factory()
            ->trigger(BotScenarioTrigger::NewCustomerRequest)
            ->published([
                'nodes' => [
                    ['id' => 'start', 'type' => 'start'],
                    ['id' => 'note', 'type' => 'text', 'text' => 'Новая заявка по вашему объявлению.'],
                    ['id' => 'ask', 'type' => 'message', 'text' => 'Возьмёте заказ?', 'channel' => 'session',
                        'options' => [['id' => 'accept', 'title' => 'Согласиться'], ['id' => 'decline', 'title' => 'Отказаться']]],
                    ['id' => 'thanks', 'type' => 'text', 'text' => 'Принято.'],
                    ['id' => 'more', 'type' => 'message', 'text' => 'Сообщить заказчику?', 'channel' => 'session',
                        'options' => [['id' => 'ok', 'title' => 'Сообщить']]],
                ],
                'edges' => [
                    ['from' => 'start', 'output' => 'continue', 'to' => 'note'],
                    ['from' => 'note', 'output' => 'continue', 'to' => 'ask'],
                    ['from' => 'ask', 'output' => 'option:accept', 'to' => 'thanks'],
                    ['from' => 'thanks', 'output' => 'continue', 'to' => 'more'],
                ],
            ])
            ->create();
        $customer = Contact::factory()->withOpenSessionWindow()->create(['phone' => '77010000001']);
        $supplier = Contact::factory()->withOpenSessionWindow()->create(['phone' => '77010000002']);
        $listing = Listing::factory()->published()->for($supplier, 'supplier')->create([
            'title' => 'Автокран 25 т', 'category_id' => categoryNamed('Автокран')->id,
        ]);
        BotSession::factory()->waitingAt('search')->create([
            'contact_id' => $customer->id,
            'bot_scenario_id' => $main->id,
            'scenario_version' => $main->published_version,
            'state' => [
                'phase' => 'choosing', 'attempts' => 0, 'clarifications' => 0,
                'transcript' => ['нужен кран'], 'query' => 'кран', 'offered' => [$listing->id],
            ],
        ]);

        // Заказчик выбирает строку прежней выдачи — заявка запускает
        // сценарий «Новая заявка» поставщику прямо посреди ответа заказчику.
        runJoinJob(joinInboundEvent($customer, [
            'type' => 'interactive',
            'payload' => ['list_reply' => ['id' => "listing:{$listing->id}", 'title' => 'Автокран 25 т']],
        ]));

        $sent = sentToDereu();
        expect($sent)->toHaveCount(3)
            ->and($sent[0]['to'])->toBe('+77010000002')
            ->and($sent[0]['payload'])->toBe(['body' => 'Новая заявка по вашему объявлению.'])
            ->and($sent[1]['to'])->toBe('+77010000002')
            ->and($sent[1]['payload']['body'])->toBe(['text' => 'Возьмёте заказ?'])
            ->and($sent[2]['to'])->toBe('+77010000001')
            ->and($sent[2]['payload']['body'])->toStartWith('Заявка по «Автокран 25 т» ушла поставщику.');

        // Нажатие поставщика — ответ ему самому: текст и вопрос — одно сообщение.
        $run = ScenarioRun::sole();
        runJoinJob(joinInboundEvent($supplier, joinPress("flow:{$run->token}:accept", 'Согласиться')));

        $reply = collect(sentToDereu())->last();
        expect(sentToDereu())->toHaveCount(4)
            ->and($reply['to'])->toBe('+77010000002')
            ->and($reply['payload']['body'])->toBe(['text' => "Принято.\n\nСообщить заказчику?"])
            ->and($reply['payload']['action']['buttons'][0]['reply']['id'])->toBe("flow:{$run->token}:ok");
    });

    test('запуск по событию — не ответ, даже если адресован тому, кому бот сейчас отвечает: уходит как раньше', function () {
        fakeDereuForJoining();
        connectedDereuCompany();
        $supplier = Contact::factory()->withOpenSessionWindow()->create();
        $listing = Listing::factory()->published()->for($supplier, 'supplier')->create();
        $scenario = BotScenario::factory()
            ->trigger(BotScenarioTrigger::ListingExpiring)
            ->published([
                'nodes' => [
                    ['id' => 'start', 'type' => 'start'],
                    ['id' => 'note', 'type' => 'text', 'text' => 'Скоро конец показа.'],
                    ['id' => 'ask', 'type' => 'message', 'text' => 'Объявление ещё актуально?', 'channel' => 'session',
                        'options' => [['id' => 'ok', 'title' => 'Да']]],
                ],
                'edges' => [
                    ['from' => 'start', 'output' => 'continue', 'to' => 'note'],
                    ['from' => 'note', 'output' => 'continue', 'to' => 'ask'],
                ],
            ])
            ->create();

        inBotReply($supplier, function () use ($supplier, $scenario, $listing): void {
            joinMessenger()->sendText($supplier, 'Ответ на сообщение.');
            app(ScenarioRunner::class)->launch($scenario, $supplier, $listing);
            joinMessenger()->sendButtons($supplier, 'Что вас интересует?', joinMenuButtons());
        });

        // Придержанный текст ответа уходит перед уведомлением, само
        // уведомление — двумя сообщениями, как раньше, а ответ дальше
        // собирается как обычно.
        expect(array_map(fn (array $sent): mixed => $sent['payload']['body'], sentToDereu()))->toBe([
            'Ответ на сообщение.',
            'Скоро конец показа.',
            ['text' => 'Объявление ещё актуально?'],
            ['text' => 'Что вас интересует?'],
        ]);
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
        expect(fn () => inBotReply($customer, function () use ($customer, $request): void {
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

    test('реплика об устаревшем варианте встаёт в начало тупика поиска с кнопкой каталога', function () {
        fakeDereuForJoining();
        connectedDereuCompany();
        $customer = Contact::factory()->withOpenSessionWindow()->create();
        $listing = Listing::factory()->expired()->create(['category_id' => categoryNamed('Автокран')->id]);
        $session = joinStaleRowSearch($customer, $listing, attempts: 0);

        inBotReply($customer, fn () => app(CustomerSearchAssistant::class)->resume(
            $session,
            ['id' => 'search', 'type' => 'ai', 'task' => 'customer_search'],
            new InboundMessage(replyId: "listing:{$listing->id}"),
        ));

        $sent = sentToDereu();
        expect($sent)->toHaveCount(1)
            ->and($sent[0]['payload']['type'])->toBe('cta_url')
            ->and($sent[0]['payload']['body']['text'])->toStartWith("Этот вариант уже сняли с публикации. Сейчас поищем свежие.\n\nПока по такому запросу пусто.")
            ->and($sent[0]['payload']['action']['parameters']['display_text'])->toBe(CustomerSearchAssistant::CATALOG_BUTTON_DEAD_END);
    });

    test('текст и прощальная ссылка в каталог при исчерпанных попытках — одно сообщение, одна строка, одно место в квоте', function () {
        $this->travelTo('2026-10-05 10:00:00');
        fakeDereuForJoining();
        connectedDereuCompany();
        $customer = Contact::factory()->withOpenSessionWindow()->create();
        $listing = Listing::factory()->expired()->create(['category_id' => categoryNamed('Автокран')->id]);
        $session = joinStaleRowSearch($customer, $listing, attempts: 3);
        $catalogUrl = app(CtaLinkBuilder::class)->catalogUrl($customer, kind: ListingKind::Rental);

        inBotReply($customer, fn () => app(CustomerSearchAssistant::class)->resume(
            $session,
            ['id' => 'search', 'type' => 'ai', 'task' => 'customer_search'],
            new InboundMessage(replyId: "listing:{$listing->id}"),
        ));

        $sent = sentToDereu();
        expect($sent)->toHaveCount(1)
            ->and($sent[0]['payload']['body'])->toBe(['text' => "Этот вариант уже сняли с публикации. Сейчас поищем свежие.\n\nПодходящего сейчас не нашлось — так бывает, база пополняется каждый день. Загляните в каталог: вдруг что-то уже появилось."])
            ->and($sent[0]['payload']['action'])->toBe([
                'name' => 'cta_url',
                'parameters' => ['display_text' => CustomerSearchAssistant::CATALOG_BUTTON_DEAD_END, 'url' => $catalogUrl],
            ]);

        $entry = ChannelMessage::sole();
        expect($entry->pricing_snapshot['position_in_month'])->toBe(1)
            ->and(app(WhatsappCostEstimator::class)->sessionMessagesInMonth(now()))->toBe(1);
    });

    test('сбой склеенной ссылки в каталог не глотает текст: он уходит с прежней формой исхода', function () {
        fakeDereuForJoining(fn (Request $request): bool => ($request['payload']['type'] ?? null) === 'cta_url');
        connectedDereuCompany();
        $customer = Contact::factory()->withOpenSessionWindow()->create();
        $listing = Listing::factory()->expired()->create(['category_id' => categoryNamed('Автокран')->id]);
        $session = joinStaleRowSearch($customer, $listing, attempts: 0);

        inBotReply($customer, fn () => app(CustomerSearchAssistant::class)->resume(
            $session,
            ['id' => 'search', 'type' => 'ai', 'task' => 'customer_search'],
            new InboundMessage(replyId: "listing:{$listing->id}"),
        ));

        $sent = sentToDereu();
        expect($sent)->toHaveCount(2)
            ->and($sent[0]['payload']['type'])->toBe('cta_url')
            ->and($sent[1]['payload']['type'])->toBe('button')
            ->and($sent[1]['payload']['body']['text'])->toStartWith("Этот вариант уже сняли с публикации. Сейчас поищем свежие.\n\nПока по такому запросу пусто.")
            ->and($sent[1]['payload']['action']['buttons'][0]['reply']['id'])->toBe(CustomerSearchAssistant::BUTTON_MENU);
    });

    test('сбой текста, вставшего в прощальную ссылку, не глотается вместе с ней — ход повторится с того же места', function () {
        fakeDereuForJoining(fn (): bool => true);
        connectedDereuCompany();
        $customer = Contact::factory()->withOpenSessionWindow()->create();
        $listing = Listing::factory()->expired()->create(['category_id' => categoryNamed('Автокран')->id]);
        $session = joinStaleRowSearch($customer, $listing, attempts: 3);

        // Сбой самой ссылки глотается, как раньше; текст, ехавший в ней,
        // пробует уйти сам — и его сбой обрывает ход до записи состояния.
        expect(fn () => inBotReply($customer, fn () => app(CustomerSearchAssistant::class)->resume(
            $session,
            ['id' => 'search', 'type' => 'ai', 'task' => 'customer_search'],
            new InboundMessage(replyId: "listing:{$listing->id}"),
        )))->toThrow(RequestException::class);

        expect(collect(sentToDereu())->pluck('type')->all())->toBe(['interactive', 'text'])
            ->and(sentToDereu()[1]['payload'])->toBe(['body' => 'Этот вариант уже сняли с публикации. Сейчас поищем свежие.'])
            ->and($session->fresh()->state['phase'])->toBe('choosing');
    });
});
