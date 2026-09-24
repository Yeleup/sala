<?php

use App\Enums\BotScenarioTrigger;
use App\Enums\ListingStatus;
use App\Models\BotScenario;
use App\Models\Listing;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

function sixtyDayListingShiftMigration(): Migration
{
    return require database_path('migrations/2026_09_24_100000_extend_published_listings_to_sixty_day_lifetime.php');
}

/**
 * База тестов уже прогнала все миграции, и снимок сдвига в ней есть —
 * пустой. Состояние «до миграции» — это база без снимка.
 */
function runSixtyDayListingShiftMigration(): void
{
    Schema::dropIfExists('listing_lifetime_extensions');

    sixtyDayListingShiftMigration()->up();
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

    test('повторный прогон тела миграции срок второй раз не сдвигает', function () {
        // Laravel записывает миграцию в журнал уже после фиксации её
        // транзакции: сбой между этими шагами повторяет up().
        $expiresAt = now()->addHours(12)->startOfSecond();
        $listing = Listing::factory()->published()->create(['expires_at' => $expiresAt, 'renewal_requested_at' => null]);

        runSixtyDayListingShiftMigration();
        sixtyDayListingShiftMigration()->up();

        expect($listing->refresh()->expires_at->toDateTimeString())
            ->toBe($expiresAt->copy()->addDays(30)->toDateTimeString());
    });

    test('повторный прогон не трогает публикацию, продлённую уже по новому сроку', function () {
        $listing = Listing::factory()->published()->create(['expires_at' => now()->addDays(3)->startOfSecond(), 'renewal_requested_at' => null]);

        runSixtyDayListingShiftMigration();

        $this->travel(2)->days();
        $listing->refresh()->renew();
        $renewedUntil = $listing->refresh()->expires_at->toDateTimeString();

        sixtyDayListingShiftMigration()->up();

        expect($listing->refresh()->expires_at->toDateTimeString())->toBe($renewedUntil)
            ->and($renewedUntil)->toBe(now()->addDays(60)->toDateTimeString());
    });

    test('откат возвращает прежний срок только ещё не продлённым публикациям', function () {
        $expiresAt = now()->addDays(4)->startOfSecond();
        $untouched = Listing::factory()->published()->create(['expires_at' => $expiresAt, 'renewal_requested_at' => null]);
        $renewedLater = Listing::factory()->published()->create(['expires_at' => $expiresAt, 'renewal_requested_at' => null]);

        runSixtyDayListingShiftMigration();
        $renewedLater->refresh()->renew();
        $renewedUntil = $renewedLater->refresh()->expires_at->toDateTimeString();

        sixtyDayListingShiftMigration()->down();

        expect($untouched->refresh()->expires_at->toDateTimeString())->toBe($expiresAt->toDateTimeString())
            ->and($renewedLater->refresh()->expires_at->toDateTimeString())->toBe($renewedUntil)
            ->and(Schema::hasTable('listing_lifetime_extensions'))->toBeFalse();
    });

    test('откат не отнимает дни у публикации, которой опрос нового цикла уже ушёл', function () {
        // Без этого откат вернул бы срок в прошлое при живом опросе у
        // поставщика, и ближайший прогон цикла сразу увёл бы объявление в архив.
        $expiresAt = now()->addDays(2)->startOfSecond();
        $listing = Listing::factory()->published()->create(['expires_at' => $expiresAt, 'renewal_requested_at' => null]);

        runSixtyDayListingShiftMigration();

        $this->travel(31)->days();
        $listing->refresh()->update(['renewal_requested_at' => now()]);

        sixtyDayListingShiftMigration()->down();

        expect($listing->refresh()->expires_at->toDateTimeString())->toBe($expiresAt->copy()->addDays(30)->toDateTimeString())
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
