<?php

use App\Enums\WhatsappTemplateCategory;
use App\Models\DereuCompany;
use App\Models\WhatsappTemplate;
use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('services.dereu.external_id', 'org_test');
    config()->set('services.dereu.platform_key', 'plat_test.secret');
    config()->set('services.dereu.base_url', 'https://api.dereu.test/api/v1');

    Http::preventStrayRequests();
});

test('синхронизация шаблонов стоит в расписании до утреннего цикла продления', function () {
    $expressions = collect(app(Schedule::class)->events())
        ->mapWithKeys(fn (ScheduledEvent $event): array => [(string) $event->command => $event->expression]);

    // Цикл продления в 04:00 — главный расход шаблонов за сутки: категории
    // и статусы должны быть свежими до того, как он начнёт слать.
    expect($expressions->first(fn (string $expr, string $command): bool => str_contains($command, 'whatsapp:sync-templates')))
        ->toBe('30 3 * * *')
        ->and($expressions->first(fn (string $expr, string $command): bool => str_contains($command, 'listings:run-renewal-cycle')))
        ->toBe('0 4 * * *');
});

test('плановая синхронизация подтягивает вердикт Meta без ручной кнопки', function () {
    connectedDereuCompany();
    WhatsappTemplate::factory()->approved()->create([
        'name' => 'listing_renewal',
        'language' => 'ru',
        'category' => WhatsappTemplateCategory::Utility,
    ]);

    Http::fake([
        'api.dereu.test/api/v1/platform/companies/org_test/templates/sync' => Http::response(['synced' => 1]),
        'api.dereu.test/api/v1/platform/companies/org_test/templates' => Http::response(['data' => [[
            'id' => 5,
            'name' => 'listing_renewal',
            'language' => 'ru',
            'category' => 'marketing',
            'status' => 'approved',
            'components' => [['type' => 'BODY', 'text' => 'Объявление скоро истечёт.']],
        ]]]),
    ]);

    $this->artisan('whatsapp:sync-templates')->assertSuccessful();

    expect(WhatsappTemplate::sole()->category)->toBe(WhatsappTemplateCategory::Marketing);
});

test('без подключённого номера плановая синхронизация пропускается молча', function () {
    DereuCompany::factory()->deactivated()->create(['external_id' => 'org_test']);
    Http::fake();

    $this->artisan('whatsapp:sync-templates')->assertSuccessful();

    Http::assertNothingSent();
});

test('сбой Dereu даёт ненулевой код выхода и запись в журнале ошибок', function () {
    Log::spy();
    connectedDereuCompany();
    Http::fake(['api.dereu.test/*' => Http::response([], 500)]);

    $this->artisan('whatsapp:sync-templates')->assertFailed();

    Log::shouldHaveReceived('error')->atLeast()->once();
});
