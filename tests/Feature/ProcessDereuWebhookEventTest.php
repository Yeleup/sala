<?php

use App\Jobs\ProcessDereuWebhookEvent;
use App\Models\Contact;
use App\Models\DereuWebhookEvent;
use App\Services\Bot\BotEngine;
use App\Services\Bot\InboundMessage;
use App\Services\DereuMessenger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

function inboundMessageEvent(array $payloadOverrides = [], array $overrides = []): DereuWebhookEvent
{
    return DereuWebhookEvent::factory()->create(array_merge([
        'payload' => array_merge([
            'event' => 'message_received',
            'from' => '77011234567',
            'type' => 'text',
            'timestamp' => now()->subMinute()->timestamp,
            'payload' => ['body' => 'Привет'],
        ], $payloadOverrides),
    ], $overrides));
}

function runDereuWebhookJob(DereuWebhookEvent $event): void
{
    app()->call([new ProcessDereuWebhookEvent($event), 'handle']);
}

test('waiting for the per-contact lock is bounded by time, not by the attempt count', function () {
    $job = new ProcessDereuWebhookEvent(inboundMessageEvent());

    // Сообщения одного контакта сериализованы: пока обрабатывается первое
    // (скачивание медиа + vision-вызов — десятки секунд), джобы остальных
    // раз за разом получают release от WithoutOverlapping. Каждый release —
    // это попытка, поэтому жёсткий $tries хоронит хвост пачки (альбом фото)
    // за ~30 секунд ожидания. Ожидание должно ограничиваться временем
    // (retryUntil), а настоящие падения — своим бюджетом maxExceptions.
    expect(property_exists($job, 'tries'))->toBeFalse()
        ->and($job->maxExceptions)->toBe(3)
        ->and($job->retryUntil()->getTimestamp())
        ->toBeGreaterThanOrEqual(now()->addMinutes(5)->getTimestamp());
});

test('an inbound message creates the contact, feeds the engine and is marked processed', function () {
    test()->mock(BotEngine::class)
        ->shouldReceive('handle')->once()
        ->withArgs(fn (Contact $contact, InboundMessage $message) => $contact->phone === '77011234567'
            && $message->text === 'Привет');

    $event = inboundMessageEvent();

    runDereuWebhookJob($event);

    $contact = Contact::sole();
    expect($contact->last_inbound_at->timestamp)->toBe($event->payload['timestamp'])
        ->and($event->fresh()->processed_at)->not->toBeNull();
});

test('an interactive reply reaches the engine with its option id', function () {
    test()->mock(BotEngine::class)
        ->shouldReceive('handle')->once()
        ->withArgs(fn (Contact $contact, InboundMessage $message) => $message->replyId === 'supplier'
            && $message->text === 'Поставщик');

    runDereuWebhookJob(inboundMessageEvent([
        'type' => 'interactive',
        'payload' => ['button_reply' => ['id' => 'supplier', 'title' => 'Поставщик']],
    ]));
});

test('an interactive reply Meta could not deliver reaches the engine as an unrecognized press', function () {
    test()->mock(BotEngine::class)
        ->shouldReceive('handle')->once()
        ->withArgs(fn (Contact $contact, InboundMessage $message) => $message->unrecognizedPress === true
            && $message->replyId === null
            && $message->text === null);

    runDereuWebhookJob(inboundMessageEvent([
        'type' => 'interactive',
        'payload' => [
            'errors' => [
                ['code' => 131000, 'title' => 'Something went wrong', 'error_data' => ['details' => 'Unsupported webhook payload']],
            ],
        ],
    ]));
});

test('an interactive event with a null payload reaches the engine as an unrecognized press', function () {
    test()->mock(BotEngine::class)
        ->shouldReceive('handle')->once()
        ->withArgs(fn (Contact $contact, InboundMessage $message) => $message->unrecognizedPress === true);

    runDereuWebhookJob(inboundMessageEvent([
        'type' => 'interactive',
        'payload' => null,
    ]));
});

test('an already processed event is not processed again', function () {
    test()->mock(BotEngine::class)->shouldNotReceive('handle');

    runDereuWebhookJob(inboundMessageEvent(overrides: ['processed_at' => now()]));

    expect(Contact::count())->toBe(0);
});

test('an out-of-order redelivery never moves the session window back', function () {
    test()->mock(BotEngine::class)->shouldReceive('handle')->once();
    $this->freezeTime();

    $contact = Contact::factory()->create(['phone' => '77011234567', 'last_inbound_at' => now()]);

    runDereuWebhookJob(inboundMessageEvent(['timestamp' => now()->subHour()->timestamp]));

    expect($contact->fresh()->last_inbound_at->timestamp)->toBe(now()->timestamp);
});

test('the profile name from the event updates the contact', function () {
    test()->mock(BotEngine::class)->shouldReceive('handle')->once();

    runDereuWebhookJob(inboundMessageEvent(['profile_name' => 'Асхат']));

    expect(Contact::sole()->profile_name)->toBe('Асхат');
});

test('the profile name from the event never touches the manually set display name', function () {
    test()->mock(BotEngine::class)->shouldReceive('handle')->once();
    Contact::factory()->create(['phone' => '77011234567', 'display_name' => 'ТОО «СтройКран»']);

    runDereuWebhookJob(inboundMessageEvent(['profile_name' => 'Асхат']));

    $contact = Contact::sole();
    expect($contact->profile_name)->toBe('Асхат')
        ->and($contact->display_name)->toBe('ТОО «СтройКран»');
});

test('an event of a foreign company is skipped without creating a contact', function () {
    config()->set('services.dereu.external_id', 'org_test');
    connectedDereuCompany(['dereu_company_id' => 'co_ours']);
    test()->mock(BotEngine::class)->shouldNotReceive('handle');

    $event = inboundMessageEvent(overrides: ['company_id' => 'co_foreign']);

    runDereuWebhookJob($event);

    expect(Contact::count())->toBe(0)
        ->and($event->fresh()->processed_at)->not->toBeNull();
});

test('an event of our own company is processed', function () {
    config()->set('services.dereu.external_id', 'org_test');
    connectedDereuCompany(['dereu_company_id' => 'co_ours']);
    test()->mock(BotEngine::class)->shouldReceive('handle')->once();
    Http::fake();

    runDereuWebhookJob(inboundMessageEvent(overrides: ['company_id' => 'co_ours']));

    expect(Contact::count())->toBe(1);
});

test('a reaction neither reaches the engine nor moves the session window', function () {
    test()->mock(BotEngine::class)->shouldNotReceive('handle');
    $this->freezeTime();

    // Реакция — не сообщение: 👍 на реплику бота не должен запускать
    // приветствие, собирать страйки «нечитаемого» в AI-блоке и — главное —
    // «открывать» 24-часовое окно, которого Meta на своей стороне не открыла.
    $contact = Contact::factory()->create([
        'phone' => '77011234567',
        'last_inbound_at' => now()->subDays(2),
    ]);

    $event = inboundMessageEvent([
        'type' => 'reaction',
        'timestamp' => now()->timestamp,
        'payload' => ['emoji' => '👍', 'message_id' => 'wamid.reacted'],
    ]);

    runDereuWebhookJob($event);

    expect($contact->fresh()->last_inbound_at->timestamp)->toBe(now()->subDays(2)->timestamp)
        ->and($event->fresh()->processed_at)->not->toBeNull();
});

test('a system message neither reaches the engine nor moves the session window', function () {
    test()->mock(BotEngine::class)->shouldNotReceive('handle');
    $this->freezeTime();

    $contact = Contact::factory()->create([
        'phone' => '77011234567',
        'last_inbound_at' => now()->subDays(2),
    ]);

    $event = inboundMessageEvent([
        'type' => 'system',
        'timestamp' => now()->timestamp,
        'payload' => ['body' => 'User changed number'],
    ]);

    runDereuWebhookJob($event);

    expect($contact->fresh()->last_inbound_at->timestamp)->toBe(now()->subDays(2)->timestamp)
        ->and($event->fresh()->processed_at)->not->toBeNull();
});

test('a job that died apologizes to the contact and marks the event processed', function () {
    $contact = Contact::factory()->create(['phone' => '77011234567', 'last_inbound_at' => now()]);
    $event = inboundMessageEvent();

    test()->mock(DereuMessenger::class)
        ->shouldReceive('sendText')->once()
        ->withArgs(fn (Contact $to, string $text) => $to->is($contact)
            && str_contains($text, 'Не получилось обработать'));

    (new ProcessDereuWebhookEvent($event))->failed(new RuntimeException('boom'));

    // Помеченное событие свипер не переигрывает: джоба уже исчерпала свои
    // попытки, и повтор часами позже запутал бы диалог сильнее молчания.
    expect($event->fresh()->processed_at)->not->toBeNull();
});

test('a failing apology still marks the event processed', function () {
    Contact::factory()->create(['phone' => '77011234567', 'last_inbound_at' => now()]);
    $event = inboundMessageEvent();

    test()->mock(DereuMessenger::class)
        ->shouldReceive('sendText')->once()->andThrow(new RuntimeException('окно закрыто'));

    (new ProcessDereuWebhookEvent($event))->failed(new RuntimeException('boom'));

    expect($event->fresh()->processed_at)->not->toBeNull();
});

test('a dead job for an unknown contact just marks the event processed', function () {
    $event = inboundMessageEvent();

    test()->mock(DereuMessenger::class)->shouldNotReceive('sendText');

    (new ProcessDereuWebhookEvent($event))->failed(new RuntimeException('boom'));

    expect($event->fresh()->processed_at)->not->toBeNull();
});

test('non-message events are ignored', function () {
    test()->mock(BotEngine::class)->shouldNotReceive('handle');

    $event = inboundMessageEvent(overrides: [
        'event' => 'message_delivered',
        'dedupe_key' => 'event:'.fake()->uuid(),
    ]);

    runDereuWebhookJob($event);

    expect(Contact::count())->toBe(0);
});

test('an inbound message is marked read with a typing indicator before the engine replies', function () {
    config()->set('services.dereu.external_id', 'org_test');
    config()->set('services.dereu.platform_key', 'plat_test.secret');
    config()->set('services.dereu.base_url', 'https://api.dereu.test/api/v1');
    connectedDereuCompany(['dereu_company_id' => 'co_ours']);
    test()->mock(BotEngine::class)->shouldReceive('handle')->once();
    Http::fake(['api.dereu.test/*' => Http::response(['success' => true])]);

    $event = inboundMessageEvent(overrides: ['company_id' => 'co_ours']);

    runDereuWebhookJob($event);

    Http::assertSent(fn (Request $request) => $request->url() === 'https://api.dereu.test/api/v1/platform/companies/org_test/messages/'.$event->wamid.'/read'
        && $request->hasHeader('Authorization', 'Bearer plat_test.secret')
        && $request['typing_indicator'] === true);
    expect($event->fresh()->processed_at)->not->toBeNull();
});

test('a failed read receipt does not cost the message its processing', function () {
    config()->set('services.dereu.external_id', 'org_test');
    config()->set('services.dereu.platform_key', 'plat_test.secret');
    config()->set('services.dereu.base_url', 'https://api.dereu.test/api/v1');
    connectedDereuCompany(['dereu_company_id' => 'co_ours']);
    test()->mock(BotEngine::class)->shouldReceive('handle')->once();
    Http::fake(['api.dereu.test/*' => Http::response(['error' => 'meta_unavailable'], 502)]);

    $event = inboundMessageEvent(overrides: ['company_id' => 'co_ours']);

    runDereuWebhookJob($event);

    expect($event->fresh()->processed_at)->not->toBeNull();
});

test('no read receipt is attempted when no Dereu company is connected', function () {
    test()->mock(BotEngine::class)->shouldReceive('handle')->once();
    Http::fake();

    runDereuWebhookJob(inboundMessageEvent());

    Http::assertNothingSent();
});
