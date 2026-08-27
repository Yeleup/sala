<?php

use App\Models\AiOperation;
use App\Models\BotSession;
use App\Models\ChannelMessage;
use App\Models\Contact;
use App\Models\CustomerRequest;
use App\Models\Listing;
use App\Models\ListingRenewalBatch;
use App\Models\ScenarioRun;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function runKazakhPhoneMigration(): void
{
    (require database_path('migrations/2026_08_27_072000_complete_country_code_in_kazakh_contact_phones.php'))->up();
}

/**
 * Контакт в том виде, в каком его завёл оператор до появления проверки
 * длины номера: казахстанский номер без кода страны.
 */
function contactWithoutCountryCode(array $attributes = []): Contact
{
    return Contact::factory()->create(array_merge([
        'phone' => '7013362215',
        'display_name' => 'Сергей',
        'profile_name' => null,
        'last_inbound_at' => null,
    ], $attributes));
}

test('контакту, заведённому без кода страны, дописывается семёрка', function () {
    $contact = contactWithoutCountryCode();

    runKazakhPhoneMigration();

    expect($contact->fresh()->phone)->toBe('77013362215');
});

test('дубль, заведённый вебхуком, сливается в исходный контакт', function () {
    $original = contactWithoutCountryCode();
    $listing = Listing::factory()->for($original, 'supplier')->create();
    $run = ScenarioRun::factory()->create(['contact_id' => $original->id]);
    $sent = ChannelMessage::factory()->outbound()->create(['contact_id' => $original->id]);

    $lastInbound = now()->subMinutes(5)->startOfSecond();
    $duplicate = Contact::factory()->create([
        'phone' => '77013362215',
        'display_name' => null,
        'profile_name' => 'Sergey',
        'last_inbound_at' => $lastInbound,
    ]);
    $received = ChannelMessage::factory()->create(['contact_id' => $duplicate->id]);
    $session = BotSession::factory()->create(['contact_id' => $duplicate->id]);

    runKazakhPhoneMigration();

    $survivor = $original->fresh();

    expect(Contact::whereKey($duplicate->id)->exists())->toBeFalse()
        ->and($survivor->phone)->toBe('77013362215')
        ->and($sent->fresh()->contact_id)->toBe($original->id)
        ->and($received->fresh()->contact_id)->toBe($original->id)
        ->and($run->fresh()->contact_id)->toBe($original->id)
        ->and($listing->fresh()->contact_id)->toBe($original->id)
        ->and($session->fresh()->contact_id)->toBe($original->id);
});

test('слияние сохраняет имя из WhatsApp и открытое окно диалога', function () {
    $original = contactWithoutCountryCode();
    $lastInbound = now()->subMinutes(5)->startOfSecond();

    Contact::factory()->create([
        'phone' => '77013362215',
        'profile_name' => 'Sergey',
        'last_inbound_at' => $lastInbound,
    ]);

    runKazakhPhoneMigration();

    $survivor = $original->fresh();

    // Имя, выбранное оператором, важнее имени профиля, но окно диалога
    // живёт на дубле — без него бот не сможет ответить бесплатно.
    expect($survivor->display_name)->toBe('Сергей')
        ->and($survivor->profile_name)->toBe('Sergey')
        ->and($survivor->last_inbound_at->timestamp)->toBe($lastInbound->timestamp);
});

test('слияние переносит заявки, где контакт указан поставщиком', function () {
    $original = contactWithoutCountryCode();
    $duplicate = Contact::factory()->create(['phone' => '77013362215']);

    $asCustomer = CustomerRequest::factory()->create(['contact_id' => $duplicate->id]);
    $asSupplier = CustomerRequest::factory()->create(['supplier_contact_id' => $duplicate->id]);
    $batch = ListingRenewalBatch::factory()->create(['contact_id' => $duplicate->id]);
    $operation = AiOperation::factory()->create(['contact_id' => $duplicate->id]);

    runKazakhPhoneMigration();

    expect($asCustomer->fresh()->contact_id)->toBe($original->id)
        ->and($asSupplier->fresh()->supplier_contact_id)->toBe($original->id)
        ->and($batch->fresh()->contact_id)->toBe($original->id)
        ->and($operation->fresh()->contact_id)->toBe($original->id);
});

test('из двух диалогов остаётся тот, что живее', function () {
    $original = contactWithoutCountryCode();
    $stale = BotSession::factory()->create([
        'contact_id' => $original->id,
        'updated_at' => now()->subDays(3),
    ]);

    $duplicate = Contact::factory()->create(['phone' => '77013362215']);
    $live = BotSession::factory()->create([
        'contact_id' => $duplicate->id,
        'updated_at' => now()->subMinutes(5),
    ]);

    runKazakhPhoneMigration();

    // На контакте один диалог: уникальный индекс не даёт перенести второй,
    // а состояние, в котором человек сейчас, — на дубле.
    expect(BotSession::whereKey($stale->id)->exists())->toBeFalse()
        ->and($live->fresh()->contact_id)->toBe($original->id);
});

test('иностранные и уже канонические номера не трогаются', function () {
    $foreign = Contact::factory()->create(['phone' => '491511234567']);
    $shortForeign = Contact::factory()->create(['phone' => '4915112345']);
    $canonical = Contact::factory()->create(['phone' => '77011234567']);
    $unfinished = Contact::factory()->create(['phone' => '770133622']);

    runKazakhPhoneMigration();

    expect($foreign->fresh()->phone)->toBe('491511234567')
        ->and($shortForeign->fresh()->phone)->toBe('4915112345')
        ->and($canonical->fresh()->phone)->toBe('77011234567')
        ->and($unfinished->fresh()->phone)->toBe('770133622');
});

test('повторный прогон миграции ничего не меняет', function () {
    $original = contactWithoutCountryCode();
    $duplicate = Contact::factory()->create(['phone' => '77013362215']);
    $received = ChannelMessage::factory()->create(['contact_id' => $duplicate->id]);

    runKazakhPhoneMigration();
    runKazakhPhoneMigration();

    expect(Contact::count())->toBe(1)
        ->and($original->fresh()->phone)->toBe('77013362215')
        ->and($received->fresh()->contact_id)->toBe($original->id);
});
