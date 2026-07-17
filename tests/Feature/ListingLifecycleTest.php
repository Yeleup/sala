<?php

use App\Enums\ListingStatus;
use App\Models\Listing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Ai\Embeddings;

uses(RefreshDatabase::class);

// Публикация синхронно (очередь sync) запускает генерацию эмбеддинга.
beforeEach(fn () => Embeddings::fake());

test('a draft goes to moderation when the supplier submits it', function () {
    $listing = Listing::factory()->create();

    $listing->submitForModeration();

    expect($listing->refresh()->status)->toBe(ListingStatus::PendingModeration);
});

test('a rejected listing can be resubmitted after editing', function () {
    $listing = Listing::factory()->rejected()->create();

    $listing->submitForModeration();

    expect($listing->refresh()->status)->toBe(ListingStatus::PendingModeration);
});

test('a published listing cannot be resubmitted for moderation', function () {
    Listing::factory()->published()->create()->submitForModeration();
})->throws(LogicException::class);

test('approving publishes the listing for 30 days and clears the old rejection reason', function () {
    $this->freezeTime();
    $listing = Listing::factory()->pendingModeration()->create(['rejection_reason' => 'Не хватало цены.']);

    $listing->approve();

    $listing->refresh();
    expect($listing->status)->toBe(ListingStatus::Published)
        ->and($listing->expires_at->toDateTimeString())->toBe(now()->addDays(30)->toDateTimeString())
        ->and($listing->rejection_reason)->toBeNull();
});

test('a listing cannot be approved outside of moderation', function () {
    Listing::factory()->create()->approve();
})->throws(LogicException::class);

test('rejecting stores the mandatory reason', function () {
    $listing = Listing::factory()->pendingModeration()->create();

    $listing->reject('Не указана цена — добавьте тариф.');

    $listing->refresh();
    expect($listing->status)->toBe(ListingStatus::Rejected)
        ->and($listing->rejection_reason)->toBe('Не указана цена — добавьте тариф.');
});

test('rejecting without a reason is impossible', function () {
    Listing::factory()->pendingModeration()->create()->reject('  ');
})->throws(InvalidArgumentException::class);

test('a listing cannot be rejected outside of moderation', function () {
    Listing::factory()->published()->create()->reject('Причина');
})->throws(LogicException::class);

test('a published listing can be archived', function () {
    $listing = Listing::factory()->published()->create();

    $listing->archive();

    expect($listing->refresh()->status)->toBe(ListingStatus::Archived);
});

test('a draft cannot be archived', function () {
    Listing::factory()->create()->archive();
})->throws(LogicException::class);

test('renewing prolongs a published listing for another 30 days', function () {
    $this->freezeTime();
    $listing = Listing::factory()->published()->create(['expires_at' => now()->addDay()]);

    $listing->renew();

    expect($listing->refresh()->expires_at->toDateTimeString())
        ->toBe(now()->addDays(30)->toDateTimeString());
});

test('only published and unexpired listings are searchable', function () {
    $published = Listing::factory()->published()->create();
    Listing::factory()->expired()->create();
    Listing::factory()->create();
    Listing::factory()->pendingModeration()->create();
    Listing::factory()->rejected()->create();
    Listing::factory()->archived()->create();

    expect(Listing::searchable()->pluck('id')->all())->toBe([$published->id]);
});
