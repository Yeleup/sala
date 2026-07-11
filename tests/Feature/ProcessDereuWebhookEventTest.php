<?php

use App\Jobs\ProcessDereuWebhookEvent;
use App\Models\Contact;
use App\Models\DereuWebhookEvent;
use App\Services\Bot\BotEngine;
use App\Services\Bot\InboundMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;

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

    runDereuWebhookJob(inboundMessageEvent(overrides: ['company_id' => 'co_ours']));

    expect(Contact::count())->toBe(1);
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
