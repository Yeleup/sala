<?php

use App\Models\DereuWebhookEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('services.dereu.webhook_secret', 'test-secret');
});

function dereuWebhookEventPayload(array $overrides = []): array
{
    return array_merge([
        'event' => 'message_received',
        'event_id' => (string) Str::ulid(),
        'company_id' => 'co_abc123',
        'phone_number_id' => '1234567890',
        'from' => '77011234567',
        'wamid' => 'wamid.HBgTest',
        'type' => 'text',
        'text' => 'Привет',
        'payload' => ['body' => 'Привет'],
        'timestamp' => 1718000000,
    ], $overrides);
}

function postSignedDereuWebhook(array $payload, ?string $secret = 'test-secret'): TestResponse
{
    $headers = [];

    if ($secret !== null) {
        $headers['X-Dereu-Signature'] = 'sha256='.hash_hmac('sha256', json_encode($payload), $secret);
    }

    return test()->postJson(route('webhooks.dereu'), $payload, $headers);
}

test('a correctly signed event is stored and acknowledged', function () {
    $payload = dereuWebhookEventPayload();

    postSignedDereuWebhook($payload)->assertNoContent();

    $event = DereuWebhookEvent::sole();
    expect($event->event)->toBe('message_received')
        ->and($event->event_id)->toBe($payload['event_id'])
        ->and($event->company_id)->toBe('co_abc123')
        ->and($event->wamid)->toBe('wamid.HBgTest')
        ->and($event->payload)->toBe($payload);
});

test('a request with an invalid signature is rejected', function () {
    postSignedDereuWebhook(dereuWebhookEventPayload(), 'wrong-secret')->assertUnauthorized();

    expect(DereuWebhookEvent::count())->toBe(0);
});

test('a request without a signature header is rejected', function () {
    postSignedDereuWebhook(dereuWebhookEventPayload(), null)->assertUnauthorized();

    expect(DereuWebhookEvent::count())->toBe(0);
});

test('requests are rejected when the webhook secret is not configured', function () {
    config()->set('services.dereu.webhook_secret', null);

    postSignedDereuWebhook(dereuWebhookEventPayload())->assertServiceUnavailable();
});

test('an event without event or event_id is rejected as invalid', function () {
    postSignedDereuWebhook(dereuWebhookEventPayload(['event_id' => '']))->assertUnprocessable();

    expect(DereuWebhookEvent::count())->toBe(0);
});

test('redelivery of the same inbound message with a new event_id is deduplicated by wamid', function () {
    postSignedDereuWebhook(dereuWebhookEventPayload(['wamid' => 'wamid.HBgSame']))->assertNoContent();
    postSignedDereuWebhook(dereuWebhookEventPayload(['wamid' => 'wamid.HBgSame']))->assertNoContent();

    expect(DereuWebhookEvent::count())->toBe(1);
});

test('delivery status events for the same wamid are stored separately per event_id', function () {
    foreach (['message_sent', 'message_delivered', 'message_read'] as $event) {
        postSignedDereuWebhook(dereuWebhookEventPayload([
            'event' => $event,
            'wamid' => 'wamid.HBgStatus',
        ]))->assertNoContent();
    }

    expect(DereuWebhookEvent::count())->toBe(3);
});

test('a duplicate delivery of the same status event_id is deduplicated', function () {
    $eventId = (string) Str::ulid();
    $payload = dereuWebhookEventPayload(['event' => 'message_delivered', 'event_id' => $eventId]);

    postSignedDereuWebhook($payload)->assertNoContent();
    postSignedDereuWebhook($payload)->assertNoContent();

    expect(DereuWebhookEvent::count())->toBe(1);
});
