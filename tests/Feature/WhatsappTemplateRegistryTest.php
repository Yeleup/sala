<?php

use App\Enums\WhatsappTemplateCategory;
use App\Enums\WhatsappTemplateStatus;
use App\Models\WhatsappTemplate;
use App\Services\WhatsappTemplateRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('services.dereu.external_id', 'org_test');
    config()->set('services.dereu.platform_key', 'plat_test.secret');
    config()->set('services.dereu.base_url', 'https://api.dereu.test/api/v1');

    Http::preventStrayRequests();
});

test('creating a template registers it in Dereu and stores a pending local row', function () {
    connectedDereuCompany(['phone_number_id' => '1234567890']);
    Http::fake([
        'api.dereu.test/api/v1/platform/companies/org_test/templates' => Http::response([
            'name' => 'listing_renewal',
            'language' => 'ru',
            'status' => 'pending',
        ], 201),
    ]);

    $template = app(WhatsappTemplateRegistry::class)->create(
        name: 'listing_renewal',
        language: 'ru',
        category: WhatsappTemplateCategory::Utility,
        body: 'Объявление «{{1}}» скоро истечёт. Оно ещё актуально?',
        components: [[
            'type' => 'BUTTONS',
            'buttons' => [
                ['type' => 'QUICK_REPLY', 'text' => 'Да, актуально'],
                ['type' => 'QUICK_REPLY', 'text' => 'Нет, в архив'],
            ],
        ]],
        example: ['body_text' => [['Автокран 25т']]],
    );

    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/platform/companies/org_test/templates')
        && $request->hasHeader('Authorization', 'Bearer plat_test.secret')
        && $request['phone_number_id'] === '1234567890'
        && $request['name'] === 'listing_renewal'
        && $request['category'] === 'utility'
        && $request['components'][0]['type'] === 'BUTTONS'
        && $request['example'] === ['body_text' => [['Автокран 25т']]]);

    expect($template->refresh())
        ->status->toBe(WhatsappTemplateStatus::Pending)
        ->body->toContain('{{1}}');
});

test('a Meta refusal on creation surfaces as an exception and stores nothing', function () {
    connectedDereuCompany();
    Http::fake([
        'api.dereu.test/*' => Http::response(['message' => 'Meta отклонила шаблон: invalid example'], 422),
    ]);

    expect(fn () => app(WhatsappTemplateRegistry::class)->create(
        name: 'bad_template',
        language: 'ru',
        category: WhatsappTemplateCategory::Utility,
        body: 'Текст с {{1}} без примера',
    ))->toThrow(RequestException::class);

    expect(WhatsappTemplate::count())->toBe(0);
});

test('sync mirrors the remote list: upserts, extracts the body and removes stale rows', function () {
    WhatsappTemplate::factory()->create(['name' => 'gone_template', 'language' => 'ru']);
    WhatsappTemplate::factory()->create(['name' => 'listing_renewal', 'language' => 'ru', 'status' => WhatsappTemplateStatus::Pending]);

    Http::fake([
        'api.dereu.test/api/v1/platform/companies/org_test/templates/sync' => Http::response(['synced' => 2]),
        'api.dereu.test/api/v1/platform/companies/org_test/templates' => Http::response(['data' => [
            [
                'id' => 11,
                'name' => 'listing_renewal',
                'language' => 'ru',
                'category' => 'utility',
                'status' => 'approved',
                'components' => [
                    ['type' => 'BODY', 'text' => 'Объявление «{{1}}» скоро истечёт.', 'example' => ['body_text' => [['Кран']]]],
                    ['type' => 'BUTTONS', 'buttons' => [['type' => 'QUICK_REPLY', 'text' => 'Да, актуально']]],
                ],
            ],
            [
                'id' => 12,
                'name' => 'request_notification',
                'language' => 'ru',
                'category' => 'marketing',
                'status' => 'rejected',
                'components' => [['type' => 'BODY', 'text' => 'Новая заявка по вашему объявлению.']],
            ],
        ]]),
    ]);

    $count = app(WhatsappTemplateRegistry::class)->sync();

    expect($count)->toBe(2)
        ->and(WhatsappTemplate::query()->where('name', 'gone_template')->exists())->toBeFalse();

    $renewal = WhatsappTemplate::query()->where('name', 'listing_renewal')->sole();
    expect($renewal)
        ->status->toBe(WhatsappTemplateStatus::Approved)
        ->body->toBe('Объявление «{{1}}» скоро истечёт.')
        ->dereu_template_id->toBe(11)
        ->and($renewal->components)->toHaveCount(2);

    expect(WhatsappTemplate::query()->where('name', 'request_notification')->sole())
        ->status->toBe(WhatsappTemplateStatus::Rejected)
        ->category->toBe(WhatsappTemplateCategory::Marketing);
});

test('deleting a template removes it in Dereu and locally', function () {
    $template = WhatsappTemplate::factory()->approved()->create(['dereu_template_id' => 42]);
    Http::fake([
        'api.dereu.test/api/v1/platform/companies/org_test/templates/42' => Http::response(['status' => 'deleted']),
    ]);

    app(WhatsappTemplateRegistry::class)->delete($template);

    Http::assertSent(fn (Request $request) => $request->method() === 'DELETE'
        && str_ends_with($request->url(), '/platform/companies/org_test/templates/42'));
    expect(WhatsappTemplate::count())->toBe(0);
});

test('a template never synced from Dereu is deleted only locally', function () {
    $template = WhatsappTemplate::factory()->create(['dereu_template_id' => null]);
    Http::fake();

    app(WhatsappTemplateRegistry::class)->delete($template);

    Http::assertNothingSent();
    expect(WhatsappTemplate::count())->toBe(0);
});
