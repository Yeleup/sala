<?php

use App\Enums\WhatsappTemplateStatus;
use App\Jobs\ApplyDereuTemplateStatus;
use App\Models\DereuWebhookEvent;
use App\Models\WhatsappTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
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

function templateStatusPayload(array $overrides = []): array
{
    return array_merge([
        'event' => 'template_status_update',
        'event_id' => (string) Str::ulid(),
        'company_id' => 'co_abc123',
        'waba_id' => '9876543210',
        'payload' => [
            'name' => 'listing_renewal',
            'language' => 'ru',
            'status' => 'approved',
            'reason' => null,
        ],
    ], $overrides);
}

function postSignedTemplateStatus(array $payload): TestResponse
{
    return test()->postJson(route('webhooks.dereu'), $payload, [
        'X-Dereu-Signature' => 'sha256='.hash_hmac('sha256', json_encode($payload), 'whsec_test'),
    ]);
}

test('a template status event is stored and queued for processing', function () {
    Queue::fake();

    postSignedTemplateStatus(templateStatusPayload())->assertNoContent();

    expect(DereuWebhookEvent::sole()->event)->toBe('template_status_update');
    Queue::assertPushed(ApplyDereuTemplateStatus::class);
});

test('an approval updates the local template status', function () {
    connectedDereuCompany(['dereu_company_id' => 'co_abc123']);
    WhatsappTemplate::factory()->create(['name' => 'listing_renewal', 'language' => 'ru']);

    postSignedTemplateStatus(templateStatusPayload());

    expect(WhatsappTemplate::sole())
        ->status->toBe(WhatsappTemplateStatus::Approved)
        ->rejection_reason->toBeNull();
    expect(DereuWebhookEvent::sole()->processed_at)->not->toBeNull();
});

test('a rejection stores the reason from Meta', function () {
    connectedDereuCompany(['dereu_company_id' => 'co_abc123']);
    WhatsappTemplate::factory()->create(['name' => 'listing_renewal', 'language' => 'ru']);

    postSignedTemplateStatus(templateStatusPayload([
        'payload' => [
            'name' => 'listing_renewal',
            'language' => 'ru',
            'status' => 'rejected',
            'reason' => 'INVALID_FORMAT: пример не совпадает с переменными',
        ],
    ]));

    expect(WhatsappTemplate::sole())
        ->status->toBe(WhatsappTemplateStatus::Rejected)
        ->rejection_reason->toBe('INVALID_FORMAT: пример не совпадает с переменными');
});

test('a re-approval after rejection clears the stored reason', function () {
    connectedDereuCompany(['dereu_company_id' => 'co_abc123']);
    WhatsappTemplate::factory()->rejected()->create(['name' => 'listing_renewal', 'language' => 'ru']);

    postSignedTemplateStatus(templateStatusPayload());

    expect(WhatsappTemplate::sole())
        ->status->toBe(WhatsappTemplateStatus::Approved)
        ->rejection_reason->toBeNull();
});

test('an event of an unknown template is acknowledged without creating anything', function () {
    connectedDereuCompany(['dereu_company_id' => 'co_abc123']);

    postSignedTemplateStatus(templateStatusPayload())->assertNoContent();

    expect(WhatsappTemplate::count())->toBe(0)
        ->and(DereuWebhookEvent::sole()->processed_at)->not->toBeNull();
});

test('an event of a foreign company does not touch local templates', function () {
    connectedDereuCompany(['dereu_company_id' => 'co_ours']);
    $template = WhatsappTemplate::factory()->create(['name' => 'listing_renewal', 'language' => 'ru']);

    postSignedTemplateStatus(templateStatusPayload(['company_id' => 'co_foreign']));

    expect($template->refresh()->status)->toBe(WhatsappTemplateStatus::Pending)
        ->and(DereuWebhookEvent::sole()->processed_at)->not->toBeNull();
});

test('a duplicate delivery of the same event id is not processed twice', function () {
    Queue::fake();
    $payload = templateStatusPayload();

    postSignedTemplateStatus($payload)->assertNoContent();
    postSignedTemplateStatus($payload)->assertNoContent();

    expect(DereuWebhookEvent::count())->toBe(1);
    Queue::assertPushed(ApplyDereuTemplateStatus::class, 1);
});
