<?php

use App\Models\DereuCompany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('services.dereu.base_url', 'https://dereu.test/api/v1');
    config()->set('services.dereu.platform_key', 'plat_test.secret');

    Http::preventStrayRequests();
});

test('provisioning a new company stores dereu_company_id and the one-time api_key', function () {
    Http::fake([
        'dereu.test/api/v1/platform/companies' => Http::response([
            'dereu_company_id' => 'co_abc123',
            'api_key' => 'dereu_secret_key',
        ], 201),
    ]);

    $this->artisan('dereu:provision-company', ['external_id' => 'org_1', '--name' => 'ООО Ромашка'])
        ->expectsOutputToContain('api_key received and stored encrypted')
        ->assertSuccessful();

    Http::assertSent(function ($request): bool {
        return $request->url() === 'https://dereu.test/api/v1/platform/companies'
            && $request->hasHeader('Authorization', 'Bearer plat_test.secret')
            && $request['external_id'] === 'org_1'
            && $request['name'] === 'ООО Ромашка';
    });

    $company = DereuCompany::sole();
    expect($company->external_id)->toBe('org_1')
        ->and($company->dereu_company_id)->toBe('co_abc123')
        ->and($company->api_key)->toBe('dereu_secret_key')
        ->and($company->getRawOriginal('api_key'))->not->toContain('dereu_secret_key');
});

test('a repeated provision keeps the locally stored api_key', function () {
    $company = DereuCompany::factory()->create([
        'external_id' => 'org_1',
        'dereu_company_id' => 'co_abc123',
        'api_key' => 'dereu_existing_key',
    ]);

    Http::fake([
        'dereu.test/api/v1/platform/companies' => Http::response([
            'dereu_company_id' => 'co_abc123',
            'already_provisioned' => true,
        ], 200),
    ]);

    $this->artisan('dereu:provision-company', ['external_id' => 'org_1'])
        ->expectsOutputToContain('api_key is not re-issued')
        ->assertSuccessful();

    expect(DereuCompany::count())->toBe(1)
        ->and($company->refresh()->api_key)->toBe('dereu_existing_key');
});

test('a repeated provision without a locally stored api_key warns about re-issuing', function () {
    Http::fake([
        'dereu.test/api/v1/platform/companies' => Http::response([
            'dereu_company_id' => 'co_abc123',
            'already_provisioned' => true,
        ], 200),
    ]);

    $this->artisan('dereu:provision-company', ['external_id' => 'org_1'])
        ->expectsOutputToContain('No api_key is stored locally')
        ->assertSuccessful();

    expect(DereuCompany::sole()->api_key)->toBeNull();
});

test('a rejected request fails the command and stores nothing', function () {
    Http::fake([
        'dereu.test/api/v1/platform/companies' => Http::response([
            'message' => 'Invalid platform key.',
        ], 401),
    ]);

    $this->artisan('dereu:provision-company', ['external_id' => 'org_1'])
        ->expectsOutputToContain('Invalid platform key.')
        ->assertFailed();

    expect(DereuCompany::count())->toBe(0);
});

test('the command fails early when the platform key is not configured', function () {
    config()->set('services.dereu.platform_key', null);

    $this->artisan('dereu:provision-company', ['external_id' => 'org_1'])
        ->expectsOutputToContain('platform key is not configured')
        ->assertFailed();

    Http::assertNothingSent();
});
