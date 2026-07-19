<?php

use App\Enums\BotScenarioTrigger;
use App\Enums\CustomerRequestStatus;
use App\Enums\ListingStatus;
use App\Enums\ScenarioRunStatus;
use App\Models\BotScenario;
use App\Models\Contact;
use App\Models\CustomerRequest;
use App\Models\Listing;
use App\Models\ScenarioRun;
use App\Models\WhatsappTemplate;
use App\Services\Bot\BotEngine;
use App\Services\Bot\InboundMessage;
use App\Services\Bot\ScenarioRunner;
use App\Services\Bot\ScenarioRunReplyHandler;
use App\Services\DereuMessenger;
use App\Services\WhatsappTemplateLibrary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;

uses(RefreshDatabase::class);

function runnerMessenger(): MockInterface
{
    return test()->mock(DereuMessenger::class);
}

function installFlowScenarios(): void
{
    test()->artisan('bot:install-default-scenario')->assertSuccessful();
    seedFlowTemplates();
}

/**
 * Registry rows of the library templates — the text source of the message
 * blocks. A pending row is enough for the in-window session variant; the
 * paid out-of-window channel additionally needs the approved status.
 */
function seedFlowTemplates(): void
{
    foreach (app(WhatsappTemplateLibrary::class)->all() as $entry) {
        WhatsappTemplate::query()->updateOrCreate(
            ['name' => $entry['name'], 'language' => $entry['language']],
            ['category' => $entry['category'], 'body' => $entry['body']],
        );
    }
}

function runnerPendingRequest(array $supplierStates = ['withOpenSessionWindow']): CustomerRequest
{
    $supplier = Contact::factory();

    foreach ($supplierStates as $state) {
        $supplier = $supplier->{$state}();
    }

    $customer = Contact::factory()->withOpenSessionWindow()->create();
    $listing = Listing::factory()->published()->for($supplier->create(), 'supplier')->create(['category_id' => categoryNamed('Автокран')->id]);

    return CustomerRequest::factory()->create([
        'contact_id' => $customer->id,
        'listing_id' => $listing->id,
        'query_text' => 'нужен кран',
    ]);
}

describe('запуск сценария «Новая заявка»', function () {
    test('в открытое окно уходит сессионное сообщение с текстом шаблона и токен-кнопками, запуск ждёт ответа', function () {
        installFlowScenarios();
        $request = runnerPendingRequest();
        $supplier = $request->listing->supplier;

        // Текст собран из тела шаблона new_customer_request ({{1}}, {{2}}
        // подставлены) — у блока своего текста нет, источник один.
        $messenger = runnerMessenger();
        $messenger->shouldReceive('sendButtons')->once()->withArgs(
            fn (Contact $contact, string $text, array $buttons): bool => $contact->is($supplier)
                && $text === 'По вашему объявлению «Автокран» новая заявка от заказчика: «нужен кран». Готовы взять заказ?'
                && preg_match('/^flow:[a-z0-9]{32}:accept$/', $buttons[0]['id']) === 1
                && $buttons[0]['title'] === 'Согласиться'
                && preg_match('/^flow:[a-z0-9]{32}:decline$/', $buttons[1]['id']) === 1,
        );

        $scenario = BotScenario::publishedForTrigger(BotScenarioTrigger::NewCustomerRequest);
        $run = app(ScenarioRunner::class)->launch($scenario, $supplier, $request);

        expect($run)->not->toBeNull()
            ->and($run->status)->toBe(ScenarioRunStatus::Active)
            ->and($run->current_node_id)->toBe('poll')
            ->and($run->scenario_version)->toBe($scenario->published_version)
            ->and($run->subject->is($request))->toBeTrue();
    });

    test('вне окна уходит утверждённый шаблон с переменными заявки и токен-payload кнопок', function () {
        installFlowScenarios();
        $template = WhatsappTemplate::query()
            ->where('name', WhatsappTemplateLibrary::NEW_CUSTOMER_REQUEST)
            ->sole();
        $template->update(['status' => \App\Enums\WhatsappTemplateStatus::Approved]);
        $request = runnerPendingRequest(['withClosedSessionWindow']);

        $messenger = runnerMessenger();
        $messenger->shouldReceive('sendTemplate')->once()->withArgs(
            fn (Contact $contact, WhatsappTemplate $sent, array $params, array $payloads): bool => $sent->is($template)
                && $params === ['Автокран', 'нужен кран']
                && count($payloads) === 2
                && str_ends_with($payloads[0], ':accept')
                && str_ends_with($payloads[1], ':decline'),
        );

        $scenario = BotScenario::publishedForTrigger(BotScenarioTrigger::NewCustomerRequest);
        $run = app(ScenarioRunner::class)->launch($scenario, $request->listing->supplier, $request);

        expect($run)->not->toBeNull()
            ->and($run->refresh()->status)->toBe(ScenarioRunStatus::Active);
    });

    test('шаблона нет в реестре — запуск падает и остаётся в журнале', function () {
        // Без строки шаблона у блока нет источника текста — не уйдёт ни
        // сессионный, ни шаблонный вариант.
        test()->artisan('bot:install-default-scenario')->assertSuccessful();
        $request = runnerPendingRequest(['withClosedSessionWindow']);

        runnerMessenger()->shouldNotReceive('sendTemplate');

        $scenario = BotScenario::publishedForTrigger(BotScenarioTrigger::NewCustomerRequest);
        $run = app(ScenarioRunner::class)->launch($scenario, $request->listing->supplier, $request);

        expect($run)->toBeNull()
            ->and(ScenarioRun::sole()->status)->toBe(ScenarioRunStatus::Failed);
    });
});

describe('ответы по токену flow:{token}:{option}', function () {
    function launchedRequestRun(): array
    {
        installFlowScenarios();
        $request = runnerPendingRequest();
        $supplier = $request->listing->supplier;

        $messenger = runnerMessenger();
        $messenger->shouldReceive('sendButtons')->once();

        $scenario = BotScenario::publishedForTrigger(BotScenarioTrigger::NewCustomerRequest);
        $run = app(ScenarioRunner::class)->launch($scenario, $supplier, $request);

        return [$run, $request, $supplier, $messenger];
    }

    test('«Согласиться» принимает заявку, уведомляет обе стороны и завершает запуск', function () {
        [$run, $request, $supplier, $messenger] = launchedRequestRun();

        $messenger->shouldReceive('sendText')->once()->withArgs(
            fn (Contact $contact, string $text): bool => $contact->is($supplier) && str_contains($text, 'сообщим заказчику'),
        );
        $messenger->shouldReceive('sendText')->once()->withArgs(
            fn (Contact $contact, string $text): bool => $contact->is($request->customer)
                && str_contains($text, 'согласился')
                && str_contains($text, ltrim($supplier->phone, '+')),
        );

        $handled = app(ScenarioRunReplyHandler::class)->handle(
            $supplier,
            new InboundMessage(text: 'Согласиться', replyId: "flow:{$run->token}:accept"),
        );

        expect($handled)->toBeTrue()
            ->and($request->refresh()->status)->toBe(CustomerRequestStatus::Accepted)
            ->and($run->refresh()->status)->toBe(ScenarioRunStatus::Completed);
    });

    test('уведомление заказчика без категории строится без скобок и слова «объявление»', function () {
        installFlowScenarios();

        $customer = Contact::factory()->withOpenSessionWindow()->create();
        $supplier = Contact::factory()->withOpenSessionWindow()->create();
        $listing = Listing::factory()->published()->for($supplier, 'supplier')->create(['category_id' => null]);
        $request = CustomerRequest::factory()->create([
            'contact_id' => $customer->id,
            'listing_id' => $listing->id,
            'query_text' => 'нужен кран',
        ]);

        $messenger = runnerMessenger();
        $messenger->shouldReceive('sendButtons')->once();
        $messenger->shouldReceive('sendText')->once()->withArgs(
            fn (Contact $contact, string $text): bool => $contact->is($supplier) && str_contains($text, 'сообщим заказчику'),
        );
        $messenger->shouldReceive('sendText')->once()->withArgs(
            fn (Contact $contact, string $text): bool => $contact->is($customer)
                && $text === sprintf('Поставщик согласился по вашей заявке. Свяжитесь с ним: +%s', ltrim($supplier->phone, '+')),
        );

        $scenario = BotScenario::publishedForTrigger(BotScenarioTrigger::NewCustomerRequest);
        $run = app(ScenarioRunner::class)->launch($scenario, $supplier, $request);

        app(ScenarioRunReplyHandler::class)->handle(
            $supplier,
            new InboundMessage(replyId: "flow:{$run->token}:accept"),
        );

        expect($request->refresh()->status)->toBe(CustomerRequestStatus::Accepted);
    });

    test('решение окончательно: клик по второй кнопке завершённого запуска ничего не меняет', function () {
        [$run, $request, $supplier, $messenger] = launchedRequestRun();

        $messenger->shouldReceive('sendText')->twice(); // подтверждение + уведомление заказчика

        app(ScenarioRunReplyHandler::class)->handle(
            $supplier,
            new InboundMessage(replyId: "flow:{$run->token}:accept"),
        );

        $messenger->shouldReceive('sendText')->once()->withArgs(
            fn (Contact $contact, string $text): bool => str_contains($text, 'уже закрыт'),
        );

        app(ScenarioRunReplyHandler::class)->handle(
            $supplier,
            new InboundMessage(replyId: "flow:{$run->token}:decline"),
        );

        expect($request->refresh()->status)->toBe(CustomerRequestStatus::Accepted);
    });

    test('чужой контакт не может ответить по чужому токену', function () {
        [$run, $request] = launchedRequestRun();
        $stranger = Contact::factory()->withOpenSessionWindow()->create();

        $handled = app(ScenarioRunReplyHandler::class)->handle(
            $stranger,
            new InboundMessage(replyId: "flow:{$run->token}:accept"),
        );

        expect($handled)->toBeTrue()
            ->and($request->refresh()->status)->toBe(CustomerRequestStatus::Pending)
            ->and($run->refresh()->status)->toBe(ScenarioRunStatus::Active);
    });

    test('движок отдаёт flow-ответ обработчику запусков, не трогая основной диалог', function () {
        [$run, $request, $supplier, $messenger] = launchedRequestRun();

        $messenger->shouldReceive('sendText')->twice();

        app(BotEngine::class)->handle(
            $supplier,
            new InboundMessage(text: 'Согласиться', replyId: "flow:{$run->token}:accept"),
        );

        expect($request->refresh()->status)->toBe(CustomerRequestStatus::Accepted);
    });
});

describe('сценарий «Продление объявления»', function () {
    test('ежедневный цикл запускает сценарий и помечает опрос отправленным', function () {
        installFlowScenarios();
        $supplier = Contact::factory()->withOpenSessionWindow()->create();
        $listing = Listing::factory()->published()->for($supplier, 'supplier')
            ->create(['category_id' => categoryNamed('Экскаватор')->id, 'expires_at' => now()->addHours(12)]);

        $messenger = runnerMessenger();
        $messenger->shouldReceive('sendButtons')->once()->withArgs(
            fn (Contact $contact, string $text, array $buttons): bool => $contact->is($supplier)
                && str_contains($text, 'Экскаватор')
                && $buttons[0]['title'] === 'Да, актуально',
        );

        $this->artisan('listings:run-renewal-cycle')->assertSuccessful();

        expect($listing->refresh()->renewal_requested_at)->not->toBeNull()
            ->and(ScenarioRun::sole()->subject->is($listing))->toBeTrue();
    });

    test('«Да, актуально» продлевает публикацию по выходу «Выполнено» действия', function () {
        installFlowScenarios();
        $supplier = Contact::factory()->withOpenSessionWindow()->create();
        $listing = Listing::factory()->published()->for($supplier, 'supplier')
            ->create(['category_id' => categoryNamed('Экскаватор')->id, 'expires_at' => now()->addHours(12)]);

        $messenger = runnerMessenger();
        $messenger->shouldReceive('sendButtons')->once();

        $scenario = BotScenario::publishedForTrigger(BotScenarioTrigger::ListingExpiring);
        $run = app(ScenarioRunner::class)->launch($scenario, $supplier, $listing);

        $messenger->shouldReceive('sendText')->once()->withArgs(
            fn (Contact $contact, string $text): bool => str_contains($text, 'Продлили') && str_contains($text, 'Экскаватор'),
        );

        app(ScenarioRunReplyHandler::class)->handle(
            $supplier,
            new InboundMessage(replyId: "flow:{$run->token}:yes"),
        );

        $listing->refresh();
        expect($listing->status)->toBe(ListingStatus::Published)
            ->and($listing->expires_at->isAfter(now()->addDays(29)))->toBeTrue()
            ->and($run->refresh()->status)->toBe(ScenarioRunStatus::Completed);
    });

    test('запоздалый ответ по уже заархивированному объявлению не воскрешает его', function () {
        installFlowScenarios();
        $supplier = Contact::factory()->withOpenSessionWindow()->create();
        $listing = Listing::factory()->published()->for($supplier, 'supplier')
            ->create(['expires_at' => now()->addHours(12)]);

        $messenger = runnerMessenger();
        $messenger->shouldReceive('sendButtons')->once();

        $scenario = BotScenario::publishedForTrigger(BotScenarioTrigger::ListingExpiring);
        $run = app(ScenarioRunner::class)->launch($scenario, $supplier, $listing);

        // Срок вышел без ответа — автоархив доменного цикла. Продление
        // скипается доменом, запуск идёт по выходу «Не выполнено».
        $listing->archive();

        $messenger->shouldReceive('sendText')->once()->withArgs(
            fn (Contact $contact, string $text): bool => str_contains($text, 'уже в архиве'),
        );

        app(ScenarioRunReplyHandler::class)->handle(
            $supplier,
            new InboundMessage(replyId: "flow:{$run->token}:yes"),
        );

        expect($listing->refresh()->status)->toBe(ListingStatus::Archived)
            ->and($run->refresh()->status)->toBe(ScenarioRunStatus::Completed);
    });

    test('запуск закреплён за версией публикации: правка сценария не перекраивает отправленные кнопки', function () {
        installFlowScenarios();
        $supplier = Contact::factory()->withOpenSessionWindow()->create();
        $listing = Listing::factory()->published()->for($supplier, 'supplier')
            ->create(['category_id' => categoryNamed('Кран')->id, 'expires_at' => now()->addHours(12)]);

        $messenger = runnerMessenger();
        $messenger->shouldReceive('sendButtons')->once();

        $scenario = BotScenario::publishedForTrigger(BotScenarioTrigger::ListingExpiring);
        $run = app(ScenarioRunner::class)->launch($scenario, $supplier, $listing);

        // Республикация: кнопка «Да» теперь ведёт сразу в конец, без продления.
        $draft = $scenario->draft_definition;
        $draft['edges'] = collect($draft['edges'])
            ->map(fn (array $edge): array => $edge['from'] === 'poll' && $edge['output'] === 'option:yes'
                ? ['from' => 'poll', 'output' => 'option:yes', 'to' => 'end']
                : $edge)
            ->all();
        $scenario->update(['draft_definition' => $draft]);
        $scenario->publishDraft();

        $messenger->shouldReceive('sendText')->once()->withArgs(
            fn (Contact $contact, string $text): bool => str_contains($text, 'Продлили'),
        );

        app(ScenarioRunReplyHandler::class)->handle(
            $supplier,
            new InboundMessage(replyId: "flow:{$run->token}:yes"),
        );

        // Запуск шёл по версии 1: объявление продлено, хотя версия 2 продление убрала.
        expect($listing->refresh()->expires_at->isAfter(now()->addDays(29)))->toBeTrue()
            ->and($run->refresh()->scenario_version)->toBe(1);
    });
});

describe('исход блока «Действие»', function () {
    test('заявка решена извне — ответ идёт по «Не выполнено» и решение не меняется', function () {
        [$run, $request, $supplier, $messenger] = launchedRequestRun();

        // Решение уже принято (например, администратором в панели), а запуск
        // всё ещё ждёт ответа: кнопка активна, домен отказывает действию.
        $request->accept();

        $messenger->shouldReceive('sendText')->once()->withArgs(
            fn (Contact $contact, string $text): bool => $contact->is($supplier) && str_contains($text, 'уже зафиксирован'),
        );

        app(ScenarioRunReplyHandler::class)->handle(
            $supplier,
            new InboundMessage(replyId: "flow:{$run->token}:decline"),
        );

        expect($request->refresh()->status)->toBe(CustomerRequestStatus::Accepted)
            ->and($run->refresh()->status)->toBe(ScenarioRunStatus::Completed);
    });

    test('неподключённый выход «Не выполнено» тихо завершает запуск без «успешного» текста', function () {
        $supplier = Contact::factory()->withOpenSessionWindow()->create();
        $listing = Listing::factory()->published()->for($supplier, 'supplier')->create(['expires_at' => now()->addHours(12)]);

        $scenario = BotScenario::factory()
            ->trigger(BotScenarioTrigger::ListingExpiring)
            ->published([
                'nodes' => [
                    ['id' => 'start', 'type' => 'start'],
                    ['id' => 'ask', 'type' => 'message', 'text' => 'Продлить объявление?', 'channel' => 'session',
                        'options' => [['id' => 'renew', 'title' => 'Продлить']]],
                    ['id' => 'do_renew', 'type' => 'action', 'action' => 'renew_listing'],
                    ['id' => 'done', 'type' => 'text', 'text' => 'Продлили объявление.'],
                    ['id' => 'end', 'type' => 'end'],
                ],
                'edges' => [
                    ['from' => 'start', 'output' => 'continue', 'to' => 'ask'],
                    ['from' => 'ask', 'output' => 'option:renew', 'to' => 'do_renew'],
                    ['from' => 'do_renew', 'output' => 'continue', 'to' => 'done'],
                    ['from' => 'done', 'output' => 'continue', 'to' => 'end'],
                ],
            ])
            ->create(['name' => 'Без ветки «Не выполнено»']);

        $messenger = runnerMessenger();
        $messenger->shouldReceive('sendButtons')->once();

        $run = app(ScenarioRunner::class)->launch($scenario, $supplier, $listing);

        $listing->archive();

        // «Продлили объявление.» не уходит: действие скипнуто, ветка успеха не идёт.
        $messenger->shouldNotReceive('sendText');

        app(ScenarioRunReplyHandler::class)->handle(
            $supplier,
            new InboundMessage(replyId: "flow:{$run->token}:renew"),
        );

        expect($listing->refresh()->status)->toBe(ListingStatus::Archived)
            ->and($run->refresh()->status)->toBe(ScenarioRunStatus::Completed);
    });

    test('best-effort действие не рвёт ветку: «Уведомить заказчика» при ожидающей заявке идёт дальше', function () {
        installFlowScenarios();
        $request = runnerPendingRequest();
        $supplier = $request->listing->supplier;

        $scenario = BotScenario::factory()
            ->trigger(BotScenarioTrigger::NewCustomerRequest)
            ->published([
                'nodes' => [
                    ['id' => 'start', 'type' => 'start'],
                    ['id' => 'ask', 'type' => 'message', 'text' => 'Сообщить заказчику?', 'channel' => 'session',
                        'options' => [['id' => 'notify', 'title' => 'Сообщить']]],
                    ['id' => 'do_notify', 'type' => 'action', 'action' => 'notify_customer'],
                    ['id' => 'after', 'type' => 'text', 'text' => 'Продолжаем дальше.'],
                    ['id' => 'end', 'type' => 'end'],
                ],
                'edges' => [
                    ['from' => 'start', 'output' => 'continue', 'to' => 'ask'],
                    ['from' => 'ask', 'output' => 'option:notify', 'to' => 'do_notify'],
                    ['from' => 'do_notify', 'output' => 'continue', 'to' => 'after'],
                    ['from' => 'after', 'output' => 'continue', 'to' => 'end'],
                ],
            ])
            ->create(['name' => 'С уведомлением']);

        $messenger = runnerMessenger();
        $messenger->shouldReceive('sendButtons')->once();

        $run = app(ScenarioRunner::class)->launch($scenario, $supplier, $request);

        // Заявка ещё Pending — уведомлять нечего, но ветка продолжается.
        $messenger->shouldReceive('sendText')->once()->withArgs(
            fn (Contact $contact, string $text): bool => $contact->is($supplier) && str_contains($text, 'Продолжаем дальше'),
        );

        app(ScenarioRunReplyHandler::class)->handle(
            $supplier,
            new InboundMessage(replyId: "flow:{$run->token}:notify"),
        );

        expect($run->refresh()->status)->toBe(ScenarioRunStatus::Completed);
    });
});

describe('действие «Отправить CTA-ссылку на кабинет»', function () {
    test('кнопка запуска присылает персональную ссылку на веб-кабинет', function () {
        $supplier = Contact::factory()->withOpenSessionWindow()->create();
        $listing = Listing::factory()->published()->for($supplier, 'supplier')->create(['expires_at' => now()->addHours(12)]);

        $scenario = BotScenario::factory()
            ->trigger(BotScenarioTrigger::ListingExpiring)
            ->published([
                'nodes' => [
                    ['id' => 'start', 'type' => 'start'],
                    ['id' => 'ask', 'type' => 'message', 'text' => 'Обновите объявления в кабинете.', 'channel' => 'session',
                        'options' => [['id' => 'update', 'title' => 'Обновить объявления']]],
                    ['id' => 'send_cta', 'type' => 'action', 'action' => 'send_cabinet_cta',
                        'text' => 'Откройте кабинет, чтобы обновить свои объявления.'],
                    ['id' => 'end', 'type' => 'end'],
                ],
                'edges' => [
                    ['from' => 'start', 'output' => 'continue', 'to' => 'ask'],
                    ['from' => 'ask', 'output' => 'option:update', 'to' => 'send_cta'],
                    ['from' => 'send_cta', 'output' => 'continue', 'to' => 'end'],
                ],
            ])
            ->create(['name' => 'С кабинетом']);

        $messenger = runnerMessenger();
        $messenger->shouldReceive('sendButtons')->once();

        $run = app(ScenarioRunner::class)->launch($scenario, $supplier, $listing);

        $messenger->shouldReceive('sendCtaUrl')->once()->withArgs(
            fn (Contact $contact, string $text, string $button, string $url): bool => $contact->is($supplier)
                && str_contains($url, "/supplier/{$supplier->id}/listings")
                && str_contains($url, 'signature='),
        );

        app(ScenarioRunReplyHandler::class)->handle(
            $supplier,
            new InboundMessage(replyId: "flow:{$run->token}:update"),
        );

        expect($run->refresh()->status)->toBe(ScenarioRunStatus::Completed);
    });
});

test('журнал запусков в админке открывается', function () {
    $this->actingAs(\App\Models\User::factory()->create());
    ScenarioRun::factory()->create();

    $this->get(\App\Filament\Resources\ScenarioRuns\ScenarioRunResource::getUrl())
        ->assertSuccessful();
});

describe('таймаут ожидания ответа', function () {
    function timeoutScenario(): BotScenario
    {
        return BotScenario::factory()
            ->trigger(BotScenarioTrigger::ListingExpiring)
            ->published([
                'nodes' => [
                    ['id' => 'start', 'type' => 'start'],
                    ['id' => 'ask', 'type' => 'message', 'text' => 'Ответьте, пожалуйста.', 'channel' => 'session',
                        'timeout_hours' => 2, 'options' => [['id' => 'ok', 'title' => 'Хорошо']]],
                    ['id' => 'thanks', 'type' => 'text', 'text' => 'Спасибо!'],
                    ['id' => 'too_late', 'type' => 'text', 'text' => 'Не дождались ответа.'],
                    ['id' => 'end', 'type' => 'end'],
                ],
                'edges' => [
                    ['from' => 'start', 'output' => 'continue', 'to' => 'ask'],
                    ['from' => 'ask', 'output' => 'option:ok', 'to' => 'thanks'],
                    ['from' => 'ask', 'output' => 'timeout', 'to' => 'too_late'],
                    ['from' => 'thanks', 'output' => 'continue', 'to' => 'end'],
                    ['from' => 'too_late', 'output' => 'continue', 'to' => 'end'],
                ],
            ])
            ->create(['name' => 'С таймаутом']);
    }

    test('по истечении срока свип ведёт запуск по ветке таймаута', function () {
        $supplier = Contact::factory()->withOpenSessionWindow()->create();

        $messenger = runnerMessenger();
        $messenger->shouldReceive('sendButtons')->once();

        $run = app(ScenarioRunner::class)->launch(timeoutScenario(), $supplier);

        expect($run->timeout_at->diffInHours(now()->addHours(2)))->toBeLessThan(1);

        $this->travel(3)->hours();
        $supplier->update(['last_inbound_at' => now()->subMinutes(5)]); // окно открыто для текста ветки

        $messenger->shouldReceive('sendText')->once()->withArgs(
            fn (Contact $contact, string $text): bool => str_contains($text, 'Не дождались'),
        );

        $this->artisan('bot:process-run-timeouts')->assertSuccessful();

        expect($run->refresh()->status)->toBe(ScenarioRunStatus::Completed)
            ->and($run->timeout_at)->toBeNull();
    });

    test('ответ вовремя снимает таймаут и идёт по кнопке', function () {
        $supplier = Contact::factory()->withOpenSessionWindow()->create();

        $messenger = runnerMessenger();
        $messenger->shouldReceive('sendButtons')->once();

        $run = app(ScenarioRunner::class)->launch(timeoutScenario(), $supplier);

        $messenger->shouldReceive('sendText')->once()->withArgs(
            fn (Contact $contact, string $text): bool => str_contains($text, 'Спасибо'),
        );

        app(ScenarioRunReplyHandler::class)->handle(
            $supplier,
            new InboundMessage(replyId: "flow:{$run->token}:ok"),
        );

        expect($run->refresh()->status)->toBe(ScenarioRunStatus::Completed);

        $this->travel(3)->hours();
        $this->artisan('bot:process-run-timeouts')->assertSuccessful();

        expect($run->refresh()->status)->toBe(ScenarioRunStatus::Completed);
    });
});
