<?php

use App\Enums\WhatsappTemplateStatus;
use App\Filament\Resources\WhatsappTemplates\Pages\CreateWhatsappTemplate;
use App\Filament\Resources\WhatsappTemplates\Pages\ListWhatsappTemplates;
use App\Models\User;
use App\Models\WhatsappTemplate;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('services.dereu.external_id', 'org_test');
    config()->set('services.dereu.platform_key', 'plat_test.secret');
    config()->set('services.dereu.base_url', 'https://api.dereu.test/api/v1');

    $this->actingAs(User::factory()->create());
});

test('the templates table shows the registry with statuses and reasons', function () {
    $approved = WhatsappTemplate::factory()->approved()->create();
    $rejected = WhatsappTemplate::factory()->rejected()->create();

    Livewire::test(ListWhatsappTemplates::class)
        ->assertCanSeeTableRecords([$approved, $rejected])
        ->assertSee('Утверждён')
        ->assertSee('Template violates WhatsApp policy.');
});

test('the sync action mirrors the Dereu list', function () {
    Http::fake([
        'api.dereu.test/api/v1/platform/companies/org_test/templates/sync' => Http::response(['synced' => 1]),
        'api.dereu.test/api/v1/platform/companies/org_test/templates' => Http::response(['data' => [[
            'id' => 5,
            'name' => 'listing_renewal',
            'language' => 'ru',
            'category' => 'utility',
            'status' => 'approved',
            'components' => [['type' => 'BODY', 'text' => 'Объявление скоро истечёт.']],
        ]]]),
    ]);

    Livewire::test(ListWhatsappTemplates::class)
        ->callAction('sync')
        ->assertNotified('Шаблоны синхронизированы: 1');

    expect(WhatsappTemplate::sole())
        ->name->toBe('listing_renewal')
        ->status->toBe(WhatsappTemplateStatus::Approved);
});

test('creating a template from the form registers it through Dereu', function () {
    connectedDereuCompany(['phone_number_id' => '1234567890']);
    Http::fake([
        'api.dereu.test/api/v1/platform/companies/org_test/templates' => Http::response([
            'name' => 'listing_renewal',
            'language' => 'ru',
            'status' => 'pending',
        ], 201),
    ]);

    Livewire::test(CreateWhatsappTemplate::class)
        ->fillForm([
            'name' => 'listing_renewal',
            'language' => 'ru',
            'category' => 'utility',
            'body' => 'Объявление «{{1}}» скоро истечёт. Оно ещё актуально?',
            // Repeater items are keyed by the child field name until dehydration.
            'body_examples' => [['value' => 'Автокран 25т']],
            'quick_replies' => [['text' => 'Да, актуально'], ['text' => 'Нет, в архив']],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(WhatsappTemplate::sole())
        ->name->toBe('listing_renewal')
        ->status->toBe(WhatsappTemplateStatus::Pending);
});

test('the form demands an example for every body placeholder', function () {
    Livewire::test(CreateWhatsappTemplate::class)
        ->fillForm([
            'name' => 'listing_renewal',
            'language' => 'ru',
            'category' => 'utility',
            'body' => 'Объявление «{{1}}» скоро истечёт, осталось {{2}} дней.',
            'body_examples' => [['value' => 'Автокран 25т']],
        ])
        ->call('create')
        ->assertHasFormErrors(['body_examples']);

    expect(WhatsappTemplate::count())->toBe(0);
});

test('the library action registers the selected standard templates', function () {
    connectedDereuCompany(['phone_number_id' => '1234567890']);
    Http::fake([
        'api.dereu.test/api/v1/platform/companies/org_test/templates' => Http::response([
            'name' => 'x',
            'language' => 'ru',
            'status' => 'pending',
        ], 201),
    ]);

    Livewire::test(ListWhatsappTemplates::class)
        ->callAction('library', [
            'templates' => [
                App\Services\WhatsappTemplateLibrary::LISTING_RENEWAL,
                App\Services\WhatsappTemplateLibrary::NEW_CUSTOMER_REQUEST,
            ],
        ])
        ->assertNotified('Добавлено шаблонов: 2');

    expect(WhatsappTemplate::count())->toBe(2)
        ->and(WhatsappTemplate::query()->pluck('status')->unique()->all())->toBe([WhatsappTemplateStatus::Pending]);
});

test('a Meta refusal of one library template does not block the others', function () {
    connectedDereuCompany(['phone_number_id' => '1234567890']);
    Http::fakeSequence('api.dereu.test/api/v1/platform/companies/org_test/templates')
        ->push(['name' => 'x', 'language' => 'ru', 'status' => 'pending'], 201)
        ->push(['message' => 'Meta отклонила шаблон'], 422);

    Livewire::test(ListWhatsappTemplates::class)
        ->callAction('library', [
            'templates' => [
                App\Services\WhatsappTemplateLibrary::LISTING_RENEWAL,
                App\Services\WhatsappTemplateLibrary::NEW_CUSTOMER_REQUEST,
            ],
        ])
        ->assertNotified('Добавлено шаблонов: 1');

    expect(WhatsappTemplate::count())->toBe(1);
});

test('the library action is disabled when every entry is already registered', function () {
    foreach (app(App\Services\WhatsappTemplateLibrary::class)->all() as $entry) {
        WhatsappTemplate::factory()->create(['name' => $entry['name'], 'language' => $entry['language']]);
    }

    Livewire::test(ListWhatsappTemplates::class)
        ->assertActionDisabled('library');
});

test('deleting a template calls Dereu and removes the row', function () {
    $template = WhatsappTemplate::factory()->approved()->create(['dereu_template_id' => 7]);
    Http::fake([
        'api.dereu.test/api/v1/platform/companies/org_test/templates/7' => Http::response(['status' => 'deleted']),
    ]);

    Livewire::test(ListWhatsappTemplates::class)
        ->callAction(TestAction::make('delete')->table($template))
        ->assertNotified('Шаблон удалён');

    expect(WhatsappTemplate::count())->toBe(0);
});
