<?php

use App\Enums\ListingStatus;
use App\Http\Requests\UpdateSupplierListingRequest;
use App\Models\Listing;
use App\Models\User;
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

test('оператор публикует свой черновик сразу, минуя очередь модерации', function () {
    $this->freezeTime();
    $operator = User::factory()->create();
    $listing = Listing::factory()->publishable()->create();

    $listing->publish($operator);

    $listing->refresh();
    expect($listing->status)->toBe(ListingStatus::Published)
        ->and($listing->expires_at->toDateTimeString())->toBe(now()->addDays(30)->toDateTimeString())
        ->and($listing->moderated_by_user_id)->toBe($operator->id)
        ->and($listing->moderated_at)->not->toBeNull();
});

test('отклонённое объявление оператор публикует после правки, причина отклонения стирается', function () {
    $listing = Listing::factory()->publishable()->rejected()->create();

    $listing->publish();

    $listing->refresh();
    expect($listing->status)->toBe(ListingStatus::Published)
        ->and($listing->rejection_reason)->toBeNull();
});

test('неполное объявление опубликовать нельзя — оно не попало бы в поиск', function () {
    // Без названия: фабрика по умолчанию оставляет его пустым.
    Listing::factory()->create(['title' => null])->publish();
})->throws(InvalidArgumentException::class);

test('чего именно не хватает для публикации, объявление сообщает само', function () {
    $listing = Listing::factory()->create([
        'title' => null,
        'price' => null,
        'category_id' => null,
    ]);

    expect($listing->missingForPublication())->toBe(['название', 'категория', 'цена'])
        ->and($listing->isReadyForPublication())->toBeFalse()
        ->and(Listing::factory()->publishable()->create()->isReadyForPublication())->toBeTrue();
});

test('публикация возможна только из черновика и отклонённого', function (string $state) {
    Listing::factory()->publishable()->{$state}()->create()->publish();
})->with(['pendingModeration', 'published', 'archived'])->throws(LogicException::class);

test('требования к публикации в админке те же, что у веб-формы поставщика', function () {
    // Иначе оператор опубликует объявление тоньше, чем поставщик обязан
    // заполнить сам — и разъедутся два описания одного правила.
    $webFormRequired = collect((new UpdateSupplierListingRequest)->rules())
        ->filter(fn (array $rules): bool => in_array('required', $rules, true))
        ->keys()
        ->sort()
        ->values()
        ->all();

    expect(collect(array_keys(Listing::PUBLICATION_FIELDS))->sort()->values()->all())
        ->toBe($webFormRequired);
});

test('вердикт модератора остаётся в объявлении', function () {
    $moderator = User::factory()->create();
    $listing = Listing::factory()->pendingModeration()->create();

    $listing->reject('Не указана цена — добавьте тариф.', $moderator);

    expect($listing->refresh())
        ->moderated_by_user_id->toBe($moderator->id)
        ->and($listing->moderated_at)->not->toBeNull();
});

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

test('the display name prefers the title and falls back to the category name', function () {
    $titled = Listing::factory()->create(['title' => 'Аренда автокрана 25 т']);
    $legacy = Listing::factory()->create(['title' => null, 'category_id' => categoryNamed('Автокран')->id]);
    $bare = Listing::factory()->create(['title' => null, 'category_id' => null]);
    // «0» — валидное, хоть и странное название: PHP-ложность строки не
    // должна подменять сохранённое значение категорией.
    $zero = Listing::factory()->create(['title' => '0']);

    expect($titled->displayName())->toBe('Аренда автокрана 25 т')
        ->and($legacy->displayName())->toBe('Автокран')
        ->and($bare->displayName())->toBeNull()
        ->and($zero->displayName())->toBe('0');
});
