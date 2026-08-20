<?php

use App\Enums\WhatsappTemplateCategory;
use App\Enums\WhatsappTemplateStatus;
use App\Models\WhatsappTemplate;
use App\Services\WhatsappTemplateLibrary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('services.dereu.external_id', 'org_test');
    config()->set('services.dereu.platform_key', 'plat_test.secret');
    config()->set('services.dereu.base_url', 'https://api.dereu.test/api/v1');

    Http::preventStrayRequests();
});

test('every library entry satisfies the Meta template constraints', function () {
    $entries = app(WhatsappTemplateLibrary::class)->all();

    expect($entries)->not->toBeEmpty();

    foreach ($entries as $entry) {
        $placeholders = preg_match_all('/\{\{\d+\}\}/', $entry['body']);

        expect($entry['name'])->toMatch('/^[a-z0-9_]+$/')
            ->and(mb_strlen($entry['body']))->toBeLessThanOrEqual(1024)
            ->and(count($entry['examples']))->toBe($placeholders)
            ->and(count($entry['quick_replies']))->toBeLessThanOrEqual(3)
            ->and($entry['title'])->not->toBeEmpty()
            ->and($entry['purpose'])->not->toBeEmpty();

        foreach ($entry['quick_replies'] as $buttonText) {
            expect(mb_strlen($buttonText))->toBeLessThanOrEqual(25);
        }
    }
});

test('library names are unique', function () {
    $names = app(WhatsappTemplateLibrary::class)->all()->pluck('name');

    expect($names->unique()->count())->toBe($names->count());
});

test('missing() hides entries that are already registered', function () {
    WhatsappTemplate::factory()->approved()->create([
        'name' => WhatsappTemplateLibrary::LISTING_RENEWAL,
        'language' => 'ru',
    ]);

    $missing = app(WhatsappTemplateLibrary::class)->missing()->pluck('name');

    expect($missing)->not->toContain(WhatsappTemplateLibrary::LISTING_RENEWAL)
        ->and($missing)->toContain(WhatsappTemplateLibrary::NEW_CUSTOMER_REQUEST);
});

test('adding a library template registers it through Dereu with buttons and examples', function () {
    connectedDereuCompany(['phone_number_id' => '1234567890']);
    Http::fake([
        'api.dereu.test/api/v1/platform/companies/org_test/templates' => Http::response([
            'name' => WhatsappTemplateLibrary::LISTING_RENEWAL,
            'language' => 'ru',
            'status' => 'pending',
        ], 201),
    ]);

    $template = app(WhatsappTemplateLibrary::class)->add(WhatsappTemplateLibrary::LISTING_RENEWAL);

    Http::assertSent(fn (Request $request) => $request['name'] === WhatsappTemplateLibrary::LISTING_RENEWAL
        && $request['components'][0]['buttons'] === [
            ['type' => 'QUICK_REPLY', 'text' => 'Да, актуально'],
            ['type' => 'QUICK_REPLY', 'text' => 'Нет, в архив'],
        ]
        && $request['example'] === ['body_text' => [['Автокран 25 т']]]);

    expect($template)
        ->status->toBe(WhatsappTemplateStatus::Pending)
        ->body->toContain('{{1}}');
});

test('adding an unknown library template throws', function () {
    expect(fn () => app(WhatsappTemplateLibrary::class)->add('nonexistent'))
        ->toThrow(InvalidArgumentException::class);
});

test('пачечный опрос называет объявление, а не только считает', function () {
    $entry = app(WhatsappTemplateLibrary::class)->all()
        ->firstWhere('name', WhatsappTemplateLibrary::SEVERAL_LISTINGS_RENEWAL);

    // Meta переклассифицировала прежнюю формулировку («У 12 ваших
    // объявлений…») в маркетинг: сообщение без конкретного объекта
    // читается как рассылка и стоит примерно вчетверо дороже.
    expect($entry)->not->toBeNull()
        ->and($entry['category'])->toBe(WhatsappTemplateCategory::Utility)
        ->and($entry['body'])->toBe('Ваше объявление «{{1}}» и ещё {{2}} скоро перестанут показываться в поиске. Они ещё актуальны?')
        ->and($entry['examples'])->toBe(['Автокран 25 т', '11 объявлений'])
        ->and($entry['quick_replies'])->toBe(['Все актуальны', 'Разобрать по одному', 'Все в архив']);
});

test('сожжённое имя listings_renewal_batch из библиотеки убрано', function () {
    // Текст поданного шаблона Meta править не даёт: старое имя больше не
    // предлагается оператору, чтобы он не завёл маркетинговый шаблон снова.
    expect(app(WhatsappTemplateLibrary::class)->all()->pluck('name'))
        ->not->toContain('listings_renewal_batch');
});
