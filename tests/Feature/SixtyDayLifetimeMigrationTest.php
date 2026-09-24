<?php

use App\Enums\BotScenarioTrigger;
use App\Enums\ListingStatus;
use App\Models\BotScenario;
use App\Models\Listing;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function runSixtyDayListingShiftMigration(): void
{
    (require database_path('migrations/2026_09_24_100000_extend_published_listings_to_sixty_day_lifetime.php'))->up();
}

function runSixtyDayScenarioTextMigration(): void
{
    (require database_path('migrations/2026_09_24_100100_update_renewal_scenario_texts_to_sixty_days.php'))->up();
}

/**
 * Сценарий продления в том виде, в каком его ставила установка при
 * 30-дневном сроке показа.
 */
function thirtyDayRenewalScenario(BotScenarioTrigger $trigger, string $renewedText): BotScenario
{
    return BotScenario::factory()
        ->trigger($trigger)
        ->published([
            'nodes' => [
                ['id' => 'start', 'type' => 'start'],
                ['id' => 'renewed_text', 'type' => 'text', 'text' => $renewedText],
                ['id' => 'archived_text', 'type' => 'text', 'text' => 'Перенесли объявление в архив — оно больше не показывается в поиске.'],
                ['id' => 'end', 'type' => 'end'],
            ],
            'edges' => [
                ['from' => 'start', 'output' => 'continue', 'to' => 'renewed_text'],
                ['from' => 'renewed_text', 'output' => 'continue', 'to' => 'end'],
            ],
        ])
        ->create();
}

function renewedTextOf(array|string|null $definition): string
{
    $decoded = is_string($definition) ? json_decode($definition, true) : $definition;

    return collect($decoded['nodes'])->firstWhere('id', 'renewed_text')['text'];
}

describe('сдвиг срока уже опубликованных объявлений', function () {
    test('опубликованное объявление без отправленного опроса получает ещё 30 дней к текущему сроку', function () {
        $expiresAt = now()->addDays(12)->startOfSecond();
        $listing = Listing::factory()->published()->create(['expires_at' => $expiresAt, 'renewal_requested_at' => null]);

        runSixtyDayListingShiftMigration();

        expect($listing->refresh()->expires_at->toDateTimeString())
            ->toBe($expiresAt->copy()->addDays(30)->toDateTimeString())
            ->and($listing->status)->toBe(ListingStatus::Published);
    });

    test('объявление, которому опрос текущего цикла уже ушёл, дослуживает цикл как есть', function () {
        $expiresAt = now()->addHours(10)->startOfSecond();
        $listing = Listing::factory()->published()->create(['expires_at' => $expiresAt, 'renewal_requested_at' => now()->subHours(14)]);

        runSixtyDayListingShiftMigration();

        expect($listing->refresh()->expires_at->toDateTimeString())->toBe($expiresAt->toDateTimeString())
            ->and($listing->renewal_requested_at)->not->toBeNull();
    });

    test('архив, черновики и объявления без срока не трогаются', function () {
        $expiresAt = now()->addDays(5)->startOfSecond();
        $archived = Listing::factory()->archived()->create(['expires_at' => $expiresAt]);
        $draft = Listing::factory()->create(['status' => ListingStatus::Draft, 'expires_at' => $expiresAt]);
        $pending = Listing::factory()->create(['status' => ListingStatus::PendingModeration, 'expires_at' => null]);
        $publishedWithoutExpiry = Listing::factory()->published()->create(['expires_at' => null]);

        runSixtyDayListingShiftMigration();

        expect($archived->refresh()->expires_at->toDateTimeString())->toBe($expiresAt->toDateTimeString())
            ->and($draft->refresh()->expires_at->toDateTimeString())->toBe($expiresAt->toDateTimeString())
            ->and($pending->refresh()->expires_at)->toBeNull()
            ->and($publishedWithoutExpiry->refresh()->expires_at)->toBeNull();
    });
});

describe('тексты сохранённых сценариев продления', function () {
    test('типовая фраза о 30 днях в сценариях продления меняется на 60 — в черновике, публикации и снимке версии', function () {
        $single = thirtyDayRenewalScenario(
            BotScenarioTrigger::ListingExpiring,
            'Продлили: объявление «{{listing.title}}» будет показываться ещё 30 дней.',
        );
        $batch = thirtyDayRenewalScenario(
            BotScenarioTrigger::ListingsExpiringBatch,
            'Продлили: эти объявления будут показываться ещё 30 дней.',
        );

        runSixtyDayScenarioTextMigration();

        $single->refresh();
        $batch->refresh();

        expect(renewedTextOf($single->published_definition))->toBe('Продлили: объявление «{{listing.title}}» будет показываться ещё 60 дней.')
            ->and(renewedTextOf($single->draft_definition))->toBe('Продлили: объявление «{{listing.title}}» будет показываться ещё 60 дней.')
            ->and(renewedTextOf($single->versions()->sole()->definition))->toBe('Продлили: объявление «{{listing.title}}» будет показываться ещё 60 дней.')
            ->and(renewedTextOf($batch->published_definition))->toBe('Продлили: эти объявления будут показываться ещё 60 дней.')
            ->and(renewedTextOf($batch->versions()->sole()->definition))->toBe('Продлили: эти объявления будут показываться ещё 60 дней.');
    });

    test('текст, который оператор переписал по-своему, остаётся как есть', function () {
        $scenario = thirtyDayRenewalScenario(
            BotScenarioTrigger::ListingExpiring,
            'Готово, объявление снова в поиске. Спасибо!',
        );

        runSixtyDayScenarioTextMigration();

        expect(renewedTextOf($scenario->refresh()->published_definition))->toBe('Готово, объявление снова в поиске. Спасибо!');
    });

    test('сценарии с другими триггерами миграция не трогает', function () {
        $other = thirtyDayRenewalScenario(
            BotScenarioTrigger::NewCustomerRequest,
            'Продлили: объявление будет показываться ещё 30 дней.',
        );

        runSixtyDayScenarioTextMigration();

        expect(renewedTextOf($other->refresh()->published_definition))->toBe('Продлили: объявление будет показываться ещё 30 дней.');
    });
});
