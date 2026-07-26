<?php

use App\Enums\DereuCompanyStatus;
use App\Jobs\ApplyDereuWabaDisconnect;
use App\Models\DereuWebhookEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('services.dereu.webhook_secret', 'whsec_test');
    config()->set('services.dereu.external_id', 'org_test');
    // The container env (QUEUE_CONNECTION=redis) overrides phpunit.xml —
    // force the sync driver so dispatched jobs run inline in these tests.
    config()->set('queue.default', 'sync');
});

function wabaDisconnectedPayload(array $overrides = []): array
{
    return array_merge([
        'event' => 'waba_disconnected',
        'event_id' => (string) Str::ulid(),
        'company_id' => 'co_abc123',
        'phone_number_id' => '1234567890',
        'waba_id' => '9876543210',
        'reason' => 'owner_transfer',
    ], $overrides);
}

function postSignedWabaDisconnected(array $payload): TestResponse
{
    return test()->postJson(route('webhooks.dereu'), $payload, [
        'X-Dereu-Signature' => 'sha256='.hash_hmac('sha256', json_encode($payload), 'whsec_test'),
    ]);
}

test('a waba_disconnected event deactivates the company and clears its api key', function () {
    $company = connectedDereuCompany(['dereu_company_id' => 'co_abc123']);

    postSignedWabaDisconnected(wabaDisconnectedPayload())->assertNoContent();

    expect($company->refresh()->status)->toBe(DereuCompanyStatus::Deactivated)
        ->and($company->api_key)->toBeNull()
        ->and(DereuWebhookEvent::sole()->processed_at)->not->toBeNull();
});

test('an event of a foreign company does not touch the local binding', function () {
    $company = connectedDereuCompany(['dereu_company_id' => 'co_ours']);

    postSignedWabaDisconnected(wabaDisconnectedPayload(['company_id' => 'co_foreign']))->assertNoContent();

    expect($company->refresh()->status)->toBe(DereuCompanyStatus::Connected)
        ->and($company->api_key)->not->toBeNull()
        ->and(DereuWebhookEvent::sole()->processed_at)->not->toBeNull();
});

test('an event without a local company is acknowledged without side effects', function () {
    postSignedWabaDisconnected(wabaDisconnectedPayload())->assertNoContent();

    expect(DereuWebhookEvent::sole()->processed_at)->not->toBeNull();
});

test('an already processed event is not applied twice', function () {
    $company = connectedDereuCompany(['dereu_company_id' => 'co_abc123']);
    $event = DereuWebhookEvent::factory()->create([
        'event' => 'waba_disconnected',
        'company_id' => 'co_abc123',
        'wamid' => null,
        'processed_at' => now(),
    ]);

    (new ApplyDereuWabaDisconnect($event))->handle();

    expect($company->refresh()->status)->toBe(DereuCompanyStatus::Connected)
        ->and($company->api_key)->not->toBeNull();
});
