<?php

use App\Enums\DereuCompanyStatus;
use App\Filament\Pages\WhatsAppSettings;
use App\Models\DereuCompany;
use App\Models\User;
use App\Services\DereuConnect;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Изолированный кэш: env контейнера перекрывает CACHE_STORE из phpunit.xml,
    // а одноразовые nonce не должны жить между тестами и попадать в dev-redis.
    config()->set('cache.default', 'array');

    config()->set('services.dereu', [
        'base_url' => 'https://dereu.test/api/v1',
        'platform_key' => 'plat_test.secret',
        'webhook_secret' => 'whsec_test',
        'external_id' => 'org_test',
        'connect' => [
            'url' => 'https://connect.dereu.test/connect',
            'signing_secret' => 'consec_test_secret',
        ],
    ]);

    Http::preventStrayRequests();

    $this->actingAs(User::factory()->create());
});

afterEach(function () {
    Str::createRandomStringsNormally();
});

/**
 * Хосты живого канала — не мок на этой машине, который гвард пропускает.
 */
function liveDereuHosts(): void
{
    config()->set('services.dereu.base_url', 'https://api.dereu.example/api/v1');
    config()->set('services.dereu.connect.url', 'https://connect.dereu.example/connect');
}

/**
 * @return array{0: string, 1: string} result + sig, как их шлёт OUT-редирект Dereu
 */
function signedDereuConnectResult(array $overrides = []): array
{
    $data = array_merge([
        'dereu_company_id' => 'co_abc123',
        'phone_number_id' => '1234567890',
        'display_phone_number' => '+7 700 123 45 67',
        'waba_id' => '9876543210',
        'status' => 'connected',
        'nonce' => 'test-nonce',
    ], $overrides);

    $result = DereuConnect::base64UrlEncode((string) json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

    return [$result, DereuConnect::sign($result, 'consec_test_secret')];
}

test('guests are redirected to the panel login', function () {
    auth()->logout();

    $this->get(WhatsAppSettings::getUrl())->assertRedirect();
});

test('the page shows the not connected state and the exact webhook url', function () {
    $this->get(WhatsAppSettings::getUrl())
        ->assertOk()
        ->assertSee('Номер не подключён')
        ->assertSee(route('webhooks.dereu'));
});

test('the page warns when integration env values are missing', function () {
    config()->set('services.dereu.connect.signing_secret', null);
    config()->set('services.dereu.platform_key', null);

    $this->get(WhatsAppSettings::getUrl())
        ->assertOk()
        ->assertSee('Интеграция с Dereu не настроена')
        ->assertSee('DEREU_PLATFORM_KEY')
        ->assertSee('DEREU_CONNECT_SECRET');

    Livewire::test(WhatsAppSettings::class)->assertActionHidden('connect');
});

test('the connect action redirects to a signed hosted signup url and stores a one-time nonce', function () {
    Str::createRandomStringsUsing(fn (): string => 'test-nonce');
    $this->freezeTime();

    $expectedUrl = app(DereuConnect::class)->connectUrl(
        externalId: 'org_test',
        returnUrl: WhatsAppSettings::getUrl(),
        nonce: 'test-nonce',
        companyName: (string) config('app.name'),
    );

    Livewire::test(WhatsAppSettings::class)
        ->callAction('connect')
        ->assertRedirect($expectedUrl);

    expect(Cache::get('dereu:connect-nonce:test-nonce'))->toBeTrue();
});

test('the connect action is visible when the number is not connected', function () {
    Livewire::test(WhatsAppSettings::class)->assertActionVisible('connect');
});

test('the connect action is hidden on a local machine, which must not touch the live number', function () {
    liveDereuHosts();
    app()->instance('env', 'local');

    Livewire::test(WhatsAppSettings::class)->assertActionHidden('connect');
});

test('the actions that reach Dereu are hidden on a local machine too', function () {
    liveDereuHosts();
    connectedDereuCompany();
    app()->instance('env', 'local');

    Livewire::test(WhatsAppSettings::class)
        ->assertActionHidden('reissueApiKey')
        ->assertActionHidden('disconnect');
});

test('the page says why it offers nothing that reaches Dereu on a local machine', function () {
    liveDereuHosts();
    app()->instance('env', 'local');

    $this->get(WhatsAppSettings::getUrl())
        ->assertOk()
        ->assertSee('копии боевых данных');
});

test('the connect action is hidden once the number is connected', function () {
    connectedDereuCompany();

    Livewire::test(WhatsAppSettings::class)->assertActionHidden('connect');
});

test('a valid OUT redirect stores the company and re-issues its api key', function () {
    Http::fake([
        'dereu.test/api/v1/platform/companies/org_test/api-key/reissue' => Http::response(['api_key' => 'dereu_new_key']),
    ]);
    Cache::put('dereu:connect-nonce:test-nonce', true, 600);
    [$result, $signature] = signedDereuConnectResult();

    $this->get(WhatsAppSettings::getUrl(['result' => $result, 'sig' => $signature]))
        ->assertRedirect(WhatsAppSettings::getUrl());

    $company = DereuCompany::sole();
    expect($company->external_id)->toBe('org_test')
        ->and($company->dereu_company_id)->toBe('co_abc123')
        ->and($company->phone_number_id)->toBe('1234567890')
        ->and($company->display_phone_number)->toBe('+7 700 123 45 67')
        ->and($company->waba_id)->toBe('9876543210')
        ->and($company->status)->toBe(DereuCompanyStatus::Connected)
        ->and($company->api_key)->toBe('dereu_new_key')
        ->and($company->getRawOriginal('api_key'))->not->toContain('dereu_new_key')
        ->and(Cache::has('dereu:connect-nonce:test-nonce'))->toBeFalse();

    Http::assertSent(function ($request): bool {
        return $request->url() === 'https://dereu.test/api/v1/platform/companies/org_test/api-key/reissue'
            && $request->hasHeader('Authorization', 'Bearer plat_test.secret');
    });

    expect(collect(session('filament.notifications'))->pluck('title'))->toContain('WhatsApp подключён');
});

test('an OUT redirect without a display phone number still connects', function () {
    Http::fake([
        'dereu.test/api/v1/platform/companies/org_test/api-key/reissue' => Http::response(['api_key' => 'dereu_new_key']),
    ]);
    Cache::put('dereu:connect-nonce:test-nonce', true, 600);
    // Dereu тянет номер из Meta мягко: поля нет и когда Graph не ответил.
    [$result, $signature] = signedDereuConnectResult(['display_phone_number' => null]);

    $this->get(WhatsAppSettings::getUrl(['result' => $result, 'sig' => $signature]))
        ->assertRedirect(WhatsAppSettings::getUrl());

    $company = DereuCompany::sole();
    expect($company->status)->toBe(DereuCompanyStatus::Connected)
        ->and($company->display_phone_number)->toBeNull();
});

test('a signup without a display phone number clears the one left from the previous number', function () {
    connectedDereuCompany([
        'status' => DereuCompanyStatus::Deactivated,
        'display_phone_number' => '+7 700 000 00 00',
        'api_key' => null,
    ]);

    Http::fake([
        'dereu.test/api/v1/platform/companies/org_test/api-key/reissue' => Http::response(['api_key' => 'dereu_new_key']),
    ]);
    Cache::put('dereu:connect-nonce:test-nonce', true, 600);
    [$result, $signature] = signedDereuConnectResult(['display_phone_number' => null]);

    $this->get(WhatsAppSettings::getUrl(['result' => $result, 'sig' => $signature]))
        ->assertRedirect(WhatsAppSettings::getUrl());

    // Прежний номер относился к прежнему phone_number_id — показывать его как
    // подключённый было бы неправдой.
    expect(DereuCompany::sole()->display_phone_number)->toBeNull();
});

test('an OUT redirect marked as transferred connects and reports the number move', function () {
    Http::fake([
        'dereu.test/api/v1/platform/companies/org_test/api-key/reissue' => Http::response(['api_key' => 'dereu_new_key']),
    ]);
    Cache::put('dereu:connect-nonce:test-nonce', true, 600);
    [$result, $signature] = signedDereuConnectResult(['transferred' => true]);

    $this->get(WhatsAppSettings::getUrl(['result' => $result, 'sig' => $signature]))
        ->assertRedirect(WhatsAppSettings::getUrl());

    expect(DereuCompany::sole()->status)->toBe(DereuCompanyStatus::Connected);

    $notifications = collect(session('filament.notifications'));
    expect($notifications->pluck('title'))->toContain('WhatsApp подключён')
        ->and($notifications->pluck('body'))
        ->toContain('Номер перенесён от прежнего партнёра — у него отправка и приём отключены.');
});

test('a repeated signup for the same external id updates the existing company', function () {
    $existing = connectedDereuCompany([
        'status' => DereuCompanyStatus::Deactivated,
        'api_key' => null,
    ]);

    Http::fake([
        'dereu.test/api/v1/platform/companies/org_test/api-key/reissue' => Http::response(['api_key' => 'dereu_new_key']),
    ]);
    Cache::put('dereu:connect-nonce:test-nonce', true, 600);
    [$result, $signature] = signedDereuConnectResult();

    $this->get(WhatsAppSettings::getUrl(['result' => $result, 'sig' => $signature]))
        ->assertRedirect(WhatsAppSettings::getUrl());

    expect(DereuCompany::count())->toBe(1)
        ->and($existing->refresh()->status)->toBe(DereuCompanyStatus::Connected)
        ->and($existing->phone_number_id)->toBe('1234567890')
        ->and($existing->api_key)->toBe('dereu_new_key');
});

test('an OUT redirect with a wrong signature stores nothing', function () {
    Http::fake();
    Cache::put('dereu:connect-nonce:test-nonce', true, 600);
    [$result] = signedDereuConnectResult();

    $this->get(WhatsAppSettings::getUrl(['result' => $result, 'sig' => 'forged']))
        ->assertRedirect(WhatsAppSettings::getUrl());

    expect(DereuCompany::count())->toBe(0)
        ->and(Cache::has('dereu:connect-nonce:test-nonce'))->toBeTrue();

    Http::assertNothingSent();
});

test('an OUT redirect with an already used nonce stores nothing', function () {
    Http::fake();
    [$result, $signature] = signedDereuConnectResult();

    $this->get(WhatsAppSettings::getUrl(['result' => $result, 'sig' => $signature]))
        ->assertRedirect(WhatsAppSettings::getUrl());

    expect(DereuCompany::count())->toBe(0);

    Http::assertNothingSent();
});

test('an OUT redirect with a non-connected status stores nothing', function () {
    Http::fake();
    Cache::put('dereu:connect-nonce:test-nonce', true, 600);
    [$result, $signature] = signedDereuConnectResult(['status' => 'pending']);

    $this->get(WhatsAppSettings::getUrl(['result' => $result, 'sig' => $signature]))
        ->assertRedirect(WhatsAppSettings::getUrl());

    expect(DereuCompany::count())->toBe(0)
        ->and(Cache::has('dereu:connect-nonce:test-nonce'))->toBeFalse();

    Http::assertNothingSent();
});

test('the company is kept even when the api key re-issue fails', function () {
    Http::fake([
        'dereu.test/api/v1/platform/companies/org_test/api-key/reissue' => Http::response(['message' => 'boom'], 500),
    ]);
    Cache::put('dereu:connect-nonce:test-nonce', true, 600);
    [$result, $signature] = signedDereuConnectResult();

    $this->get(WhatsAppSettings::getUrl(['result' => $result, 'sig' => $signature]))
        ->assertRedirect(WhatsAppSettings::getUrl());

    $company = DereuCompany::sole();
    expect($company->status)->toBe(DereuCompanyStatus::Connected)
        ->and($company->api_key)->toBeNull();

    expect(collect(session('filament.notifications'))->pluck('title'))
        ->toContain('Номер подключён, но API-ключ не получен');
});

test('the connected state shows the number details', function () {
    connectedDereuCompany([
        'phone_number_id' => '111222333',
        'display_phone_number' => '+7 707 111 22 33',
        'waba_id' => '999888777',
        'dereu_company_id' => 'co_visible',
    ]);

    $this->get(WhatsAppSettings::getUrl())
        ->assertOk()
        ->assertSee('Подключённый номер')
        ->assertSee('+7 707 111 22 33')
        ->assertSee('111222333')
        ->assertSee('999888777')
        ->assertSee('co_visible');
});

test('the connected state stays readable when Meta gave no display phone number', function () {
    connectedDereuCompany(['display_phone_number' => null, 'phone_number_id' => '111222333']);

    $this->get(WhatsAppSettings::getUrl())
        ->assertOk()
        ->assertSee('Meta не отдала номер')
        ->assertSee('111222333');
});

test('the disconnect action deprovisions the company and deactivates it locally', function () {
    $company = connectedDereuCompany();

    Http::fake([
        'dereu.test/api/v1/platform/companies/org_test' => Http::response([
            'dereu_company_id' => $company->dereu_company_id,
            'deactivated' => true,
            'purged' => false,
        ]),
    ]);

    Livewire::test(WhatsAppSettings::class)
        ->callAction('disconnect')
        ->assertNotified('Номер отключён');

    expect($company->refresh()->status)->toBe(DereuCompanyStatus::Deactivated)
        ->and($company->api_key)->toBeNull();

    Http::assertSent(function ($request): bool {
        return $request->method() === 'DELETE'
            && $request->url() === 'https://dereu.test/api/v1/platform/companies/org_test';
    });
});

test('a company already deactivated on the Dereu side is still deactivated locally', function () {
    $company = connectedDereuCompany();

    Http::fake([
        'dereu.test/api/v1/platform/companies/org_test' => Http::response(['message' => 'gone'], 410),
    ]);

    Livewire::test(WhatsAppSettings::class)->callAction('disconnect');

    expect($company->refresh()->status)->toBe(DereuCompanyStatus::Deactivated);
});

test('the company stays connected when Dereu rejects the deprovision', function () {
    $company = connectedDereuCompany();

    Http::fake([
        'dereu.test/api/v1/platform/companies/org_test' => Http::response(['message' => 'boom'], 500),
    ]);

    Livewire::test(WhatsAppSettings::class)
        ->callAction('disconnect')
        ->assertNotified('Не удалось отключить номер');

    expect($company->refresh()->status)->toBe(DereuCompanyStatus::Connected);
});

test('the reissue action stores a fresh api key', function () {
    $company = connectedDereuCompany(['api_key' => null]);

    Http::fake([
        'dereu.test/api/v1/platform/companies/org_test/api-key/reissue' => Http::response(['api_key' => 'dereu_fresh_key']),
    ]);

    Livewire::test(WhatsAppSettings::class)
        ->callAction('reissueApiKey')
        ->assertNotified('API-ключ сохранён');

    expect($company->refresh()->api_key)->toBe('dereu_fresh_key');
});
