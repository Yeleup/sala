<?php

use App\Enums\BotScenarioTrigger;
use App\Enums\ListingStatus;
use App\Models\BotScenario;
use App\Models\Contact;
use App\Models\Listing;
use App\Models\WhatsappTemplate;
use App\Services\DereuMessenger;
use App\Services\WhatsappTemplateLibrary;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Сценарий пачки в том виде, в каком его публиковал прежний релиз: старый
 * шаблон и снятая с тех пор переменная.
 */
function staleBatchScenario(): BotScenario
{
    $scenario = BotScenario::factory()->trigger(BotScenarioTrigger::ListingsExpiringBatch)->create([
        'draft_definition' => [
            'nodes' => [
                ['id' => 'start', 'type' => 'start', 'x' => 0, 'y' => 0],
                ['id' => 'poll', 'type' => 'message', 'x' => 200, 'y' => 0,
                    'channel' => 'adaptive',
                    'template_name' => 'listings_renewal_batch',
                    'variables' => ['listings.expiring'],
                    'options' => [
                        ['id' => 'all_yes', 'title' => 'Все актуальны'],
                        ['id' => 'one_by_one', 'title' => 'Разобрать по одному'],
                        ['id' => 'all_no', 'title' => 'Все в архив'],
                    ]],
                ['id' => 'end', 'type' => 'end', 'x' => 400, 'y' => 0],
            ],
            'edges' => [
                ['from' => 'start', 'output' => 'continue', 'to' => 'poll'],
                ['from' => 'poll', 'output' => 'option:all_yes', 'to' => 'end'],
                ['from' => 'poll', 'output' => 'option:one_by_one', 'to' => 'end'],
                ['from' => 'poll', 'output' => 'option:all_no', 'to' => 'end'],
            ],
        ],
    ]);

    $scenario->publishDraft();

    return $scenario->refresh();
}

function runRepointMigration(): void
{
    (require database_path('migrations/2026_08_20_103415_repoint_batch_renewal_scenarios_to_several_listings_renewal.php'))->up();
}

test('миграция переводит сохранённые сценарии пачки на новый шаблон и его переменные', function () {
    $scenario = staleBatchScenario();

    runRepointMigration();

    $poll = collect($scenario->refresh()->published_definition['nodes'])->firstWhere('id', 'poll');
    $draftPoll = collect($scenario->draft_definition['nodes'])->firstWhere('id', 'poll');
    $versionPoll = collect($scenario->versions()->sole()->definition['nodes'])->firstWhere('id', 'poll');

    expect($poll['template_name'])->toBe('several_listings_renewal')
        ->and($poll['variables'])->toBe(['listings.expiring_first', 'listings.expiring_rest'])
        ->and($draftPoll['template_name'])->toBe('several_listings_renewal')
        ->and($versionPoll['template_name'])->toBe('several_listings_renewal')
        // Кнопки не трогаются: по их id маршрутизируются ответы уже
        // отправленных сообщений.
        ->and(collect($poll['options'])->pluck('id')->all())->toBe(['all_yes', 'one_by_one', 'all_no']);
});

test('после миграции опрос не уходит старым маркетинговым шаблоном, а вырождается в поштучные вопросы', function () {
    // Ровно состояние прода на момент деплоя: старый шаблон ещё в реестре
    // и утверждён, новый — на модерации Meta. Без миграции сюда уходило бы
    // платное «У — скоро закончится срок показа в поиске».
    staleBatchScenario();
    WhatsappTemplate::factory()->approved()->create(['name' => 'listings_renewal_batch', 'language' => 'ru']);
    $single = WhatsappTemplate::factory()->approved()->create([
        'name' => WhatsappTemplateLibrary::LISTING_RENEWAL,
        'language' => 'ru',
    ]);

    $supplier = Contact::factory()->withClosedSessionWindow()->create();
    Listing::factory()->count(3)->published()->for($supplier, 'supplier')
        ->create(['expires_at' => now()->addHours(12)]);

    runRepointMigration();

    $messenger = test()->mock(DereuMessenger::class);
    $messenger->shouldReceive('sendTemplate')->times(3)->withArgs(
        fn (Contact $contact, WhatsappTemplate $sent): bool => $sent->is($single),
    );

    $this->artisan('listings:run-renewal-cycle')->assertSuccessful();

    expect(Listing::query()->where('status', ListingStatus::Published)->count())->toBe(3);
});
