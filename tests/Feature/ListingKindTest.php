<?php

use App\Enums\LicenceType;
use App\Enums\ListingKind;
use App\Enums\ListingMediaType;
use App\Models\Listing;
use App\Models\ListingMedia;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('вид по умолчанию — аренда, и фолбэк из мусора — аренда', function () {
    expect(Listing::factory()->create()->kind)->toBe(ListingKind::Rental)
        ->and(ListingKind::fromNode(null))->toBe(ListingKind::Rental)
        ->and(ListingKind::fromNode('garbage'))->toBe(ListingKind::Rental)
        ->and(ListingKind::fromNode('driver'))->toBe(ListingKind::Driver);
});

test('поля публикации у каждого вида свои, и у водителя нет цены', function () {
    expect(array_keys(ListingKind::Rental->publicationFields()))
        ->toBe(['title', 'category_id', 'description', 'location_id', 'price'])
        ->and(array_keys(ListingKind::Driver->publicationFields()))->not->toContain('price', 'category_id')
        ->and(array_keys(ListingKind::Repair->publicationFields()))->not->toContain('price');
});

test('водитель хранит технику связью, а документ — непубличным медиа', function () {
    $listing = Listing::factory()->create([
        'kind' => ListingKind::Driver, 'licence_type' => LicenceType::TractorOperator,
    ]);
    $listing->machineCategories()->sync([categoryNamed('Экскаватор')->id, categoryNamed('Самосвал')->id]);
    ListingMedia::create([
        'listing_id' => $listing->id, 'type' => ListingMediaType::Document,
        'disk' => 'local', 'path' => 'listings/1/documents/doc.jpg',
    ]);

    expect($listing->machineCategories()->pluck('name')->sort()->values()->all())
        ->toBe(['Самосвал', 'Экскаватор'])
        ->and($listing->documents()->count())->toBe(1)
        ->and($listing->photos()->count())->toBe(0);   // документ — не фото
});
