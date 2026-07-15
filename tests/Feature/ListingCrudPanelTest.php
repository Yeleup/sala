<?php

use App\Enums\ListingMediaType;
use App\Enums\ListingStatus;
use App\Enums\ListingType;
use App\Filament\Resources\Listings\Pages\CreateListing;
use App\Filament\Resources\Listings\Pages\EditListing;
use App\Filament\Resources\Listings\Pages\ListListings;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Contact;
use App\Models\CustomerRequest;
use App\Models\Listing;
use App\Models\ListingMedia;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

test('оператор создаёт объявление за поставщика — оно появляется черновиком', function () {
    $supplier = Contact::factory()->create();
    $category = Category::factory()->create(['name' => 'Автокран']);

    Livewire::test(CreateListing::class)
        ->fillForm([
            'contact_id' => $supplier->id,
            'type' => ListingType::Equipment->value,
            'category_id' => $category->id,
            'description' => 'Кран 25 тонн, стрела 28 м',
            'location_id' => locationNamed('г.Шымкент')->id,
            'price' => '20000 тг/ч',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Listing::sole())
        ->contact_id->toBe($supplier->id)
        ->status->toBe(ListingStatus::Draft)
        ->category->name->toBe('Автокран')
        ->location->name->toBe('г.Шымкент');
});

test('без поставщика и типа объявление не создаётся', function () {
    Livewire::test(CreateListing::class)
        ->fillForm(['description' => 'Без обязательных полей'])
        ->call('create')
        ->assertHasFormErrors(['contact_id', 'type']);

    expect(Listing::count())->toBe(0);
});

test('оператор редактирует бизнес-поля объявления', function () {
    $listing = Listing::factory()->create();
    $category = Category::factory()->create(['name' => 'Экскаватор']);

    Livewire::test(EditListing::class, ['record' => $listing->id])
        ->fillForm([
            'category_id' => $category->id,
            'description' => 'Гусеничный экскаватор',
            'price' => '15000 тг/ч',
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($listing->refresh())
        ->category->name->toBe('Экскаватор')
        ->description->toBe('Гусеничный экскаватор')
        ->price->toBe('15000 тг/ч');
});

test('оператор задаёт объявлению марку из справочника', function () {
    $listing = Listing::factory()->create();
    $brand = Brand::factory()->create(['name' => 'Hitachi']);

    Livewire::test(EditListing::class, ['record' => $listing->id])
        ->fillForm(['brand_id' => $brand->id])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($listing->refresh())->brand->name->toBe('Hitachi');
});

test('смена типа на услугу убирает марку с объявления', function () {
    $listing = Listing::factory()->create(['brand_id' => Brand::factory()->create()->id]);
    $serviceCategory = Category::factory()->service()->create();

    Livewire::test(EditListing::class, ['record' => $listing->id])
        ->fillForm([
            'type' => ListingType::Service->value,
            'category_id' => $serviceCategory->id,
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($listing->refresh())
        ->type->toBe(ListingType::Service)
        ->brand_id->toBeNull();
});

test('оператор добавляет фото объявлению через форму', function () {
    Storage::fake('public');
    $listing = Listing::factory()->create();

    Livewire::test(EditListing::class, ['record' => $listing->id])
        ->fillForm(['photos' => [['path' => [UploadedFile::fake()->image('crane.jpg')]]]])
        ->call('save')
        ->assertHasNoFormErrors();

    $photo = ListingMedia::sole();
    expect($photo)
        ->listing_id->toBe($listing->id)
        ->type->toBe(ListingMediaType::Photo);
    Storage::disk('public')->assertExists($photo->path);
});

test('оператор убирает фото из формы — файл удаляется с диска', function () {
    Storage::fake('public');
    $listing = Listing::factory()->create();
    Storage::disk('public')->put('listing-photos/old.jpg', 'JPEG');
    ListingMedia::factory()->for($listing)->create(['path' => 'listing-photos/old.jpg']);

    Livewire::test(EditListing::class, ['record' => $listing->id])
        ->fillForm(['photos' => []])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(ListingMedia::count())->toBe(0);
    Storage::disk('public')->assertMissing('listing-photos/old.jpg');
});

test('черновик отправляется на модерацию из таблицы', function () {
    $listing = Listing::factory()->create();

    Livewire::test(ListListings::class)
        ->filterTable('status', ListingStatus::Draft->value)
        ->callAction(TestAction::make('submitForModeration')->table($listing))
        ->assertNotified('Объявление отправлено на модерацию');

    expect($listing->refresh())->status->toBe(ListingStatus::PendingModeration);
});

test('удаление объявления стирает файлы медиа и заявки по нему', function () {
    Storage::fake('public');
    $listing = Listing::factory()->pendingModeration()->create();
    Storage::disk('public')->put("listings/{$listing->id}/photos/photo.jpg", 'JPEG');
    ListingMedia::create([
        'listing_id' => $listing->id,
        'type' => ListingMediaType::Photo,
        'path' => "listings/{$listing->id}/photos/photo.jpg",
    ]);
    CustomerRequest::factory()->create(['listing_id' => $listing->id]);

    Livewire::test(ListListings::class)
        ->callAction(TestAction::make('delete')->table($listing));

    expect(Listing::count())->toBe(0)
        ->and(ListingMedia::count())->toBe(0)
        ->and(CustomerRequest::count())->toBe(0);
    Storage::disk('public')->assertMissing("listings/{$listing->id}/photos/photo.jpg");
});

test('bulk-удаление стирает выбранные объявления вместе с файлами медиа', function () {
    Storage::fake('public');
    $listings = Listing::factory()->count(2)->pendingModeration()->create();
    $kept = Listing::factory()->pendingModeration()->create();

    foreach ($listings as $listing) {
        Storage::disk('public')->put("listings/{$listing->id}/photos/photo.jpg", 'JPEG');
        ListingMedia::create([
            'listing_id' => $listing->id,
            'type' => ListingMediaType::Photo,
            'path' => "listings/{$listing->id}/photos/photo.jpg",
        ]);
    }

    Livewire::test(ListListings::class)
        ->selectTableRecords($listings->pluck('id')->all())
        ->callAction(TestAction::make('delete')->table()->bulk());

    expect(Listing::pluck('id')->all())->toBe([$kept->id])
        ->and(ListingMedia::count())->toBe(0);

    foreach ($listings as $listing) {
        Storage::disk('public')->assertMissing("listings/{$listing->id}/photos/photo.jpg");
    }
});
