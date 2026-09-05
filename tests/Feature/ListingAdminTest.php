<?php

use App\Enums\ListingKind;
use App\Enums\ListingMediaType;
use App\Enums\RepairPlace;
use App\Filament\Resources\Listings\ListingResource;
use App\Filament\Resources\Listings\Pages\CreateListing;
use App\Filament\Resources\Listings\Pages\EditListing;
use App\Filament\Resources\Listings\Pages\ListListings;
use App\Jobs\GenerateListingEmbedding;
use App\Models\Contact;
use App\Models\Listing;
use App\Models\ListingMedia;
use App\Models\User;
use Filament\Schemas\Components\Component;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('снимок документа закрыт для незалогиненных — гость уходит на вход в админку', function () {
    $listing = Listing::factory()->driver()->create();

    $this->get(route('moderation.listings.document', $listing))
        ->assertRedirect(route('filament.admin.auth.login'));
});

describe('форма объявления по виду', function () {
    beforeEach(fn () => $this->actingAs(User::factory()->create()));

    test('форма показывает поля вида и прячет чужие', function (string $state, array $visible, array $hidden) {
        $listing = Listing::factory()->{$state}()->create();

        $component = Livewire::test(EditListing::class, ['record' => $listing->id]);

        foreach ($visible as $field) {
            $component->assertSchemaComponentVisible($field);
        }

        foreach ($hidden as $field) {
            $component->assertSchemaComponentHidden($field);
        }
    })->with([
        'аренда' => [
            'publishable',
            ['category_id', 'brand_id', 'price'],
            ['person_name', 'services', 'repair_place', 'machine_categories', 'unlisted_machinery', 'licence_type', 'experience_years', 'travels_to_other_cities', 'document_verified'],
        ],
        'ремонт' => [
            'repair',
            ['person_name', 'services', 'repair_place', 'price'],
            ['category_id', 'brand_id', 'machine_categories', 'unlisted_machinery', 'licence_type', 'experience_years', 'travels_to_other_cities', 'document_verified'],
        ],
        'водитель' => [
            'driver',
            ['person_name', 'machine_categories', 'unlisted_machinery', 'licence_type', 'experience_years', 'travels_to_other_cities', 'document_verified'],
            ['category_id', 'brand_id', 'price', 'services', 'repair_place'],
        ],
    ]);

    test('техника вне справочника у водителя сохраняется словами и не длиннее колонки', function () {
        $driver = Listing::factory()->driver()->create();

        Livewire::test(EditListing::class, ['record' => $driver->id])
            ->assertSchemaComponentExists('unlisted_machinery', checkComponentUsing: fn (Component $field): bool => $field->getLabel() === 'Техника вне справочника')
            ->fillForm(['unlisted_machinery' => 'Автобус'])
            ->call('save')
            ->assertHasNoFormErrors();

        expect($driver->refresh()->unlisted_machinery)->toBe('Автобус');

        // Колонка в базе на 120 символов — форма не пропускает длиннее.
        Livewire::test(EditListing::class, ['record' => $driver->id])
            ->fillForm(['unlisted_machinery' => str_repeat('а', 121)])
            ->call('save')
            ->assertHasFormErrors(['unlisted_machinery' => ['max']]);
    });

    test('вид выбирается при создании, а на редактировании заморожен', function () {
        Livewire::test(CreateListing::class)
            ->assertFormSet(['kind' => ListingKind::Rental->value])
            ->assertSchemaComponentExists('kind', checkComponentUsing: fn (Component $field): bool => ! $field->isDisabled());

        // Смена вида у готового объявления оставила бы полузаполненную
        // анкету чужого вида — вид задаётся один раз, при создании.
        Livewire::test(EditListing::class, ['record' => Listing::factory()->repair()->create()->id])
            ->assertSchemaComponentExists('kind', checkComponentUsing: fn (Component $field): bool => $field->isDisabled());
    });

    test('у ремонта цена подписана как цена за диагностику', function () {
        Livewire::test(EditListing::class, ['record' => Listing::factory()->repair()->create()->id])
            ->assertSchemaComponentExists('price', checkComponentUsing: fn (Component $field): bool => $field->getLabel() === 'Цена за диагностику');

        Livewire::test(EditListing::class, ['record' => Listing::factory()->create()->id])
            ->assertSchemaComponentExists('price', checkComponentUsing: fn (Component $field): bool => $field->getLabel() === 'Цена/Тариф');
    });

    test('оператор заводит объявление ремонта — вид и поля анкеты сохраняются', function () {
        Livewire::test(CreateListing::class)
            ->fillForm([
                'contact_id' => Contact::factory()->create()->id,
                'kind' => ListingKind::Repair->value,
                'title' => 'Ремонт гидравлики',
                'person_name' => 'Сервис «Мотор»',
                'services' => 'Гидравлика, ходовая, электрика',
                'repair_place' => RepairPlace::Both->value,
                'location_id' => locationNamed('г.Шымкент')->id,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        expect(Listing::sole())
            ->kind->toBe(ListingKind::Repair)
            ->person_name->toBe('Сервис «Мотор»')
            ->services->toBe('Гидравлика, ходовая, электрика')
            ->repair_place->toBe(RepairPlace::Both);
    });

    test('«создать ещё» сохраняет выбранный вид', function () {
        // Мастера, как и технику, заводят пачками: три объявления ремонта
        // подряд не должны требовать выбирать вид заново.
        Livewire::test(CreateListing::class)
            ->fillForm([
                'contact_id' => Contact::factory()->create()->id,
                'kind' => ListingKind::Repair->value,
            ])
            ->call('create', another: true)
            ->assertHasNoFormErrors()
            ->assertFormSet(['kind' => ListingKind::Repair->value]);
    });

    test('подсказка «чего не хватает» считается по виду', function () {
        // У водителя с заполненной анкетой не хватает только снимка
        // удостоверения — он приходит в чате, форма его не загружает.
        $driver = Listing::factory()->driver()->create(['title' => 'Машинист экскаватора']);

        Livewire::test(EditListing::class, ['record' => $driver->id])
            ->assertSee('Не хватает для публикации: фото документа.');

        ListingMedia::create([
            'listing_id' => $driver->id, 'type' => ListingMediaType::Document,
            'disk' => 'local', 'path' => "listings/{$driver->id}/documents/doc.jpg",
        ]);

        Livewire::test(EditListing::class, ['record' => $driver->id])
            ->assertSee('Все поля заполнены — объявление можно публиковать.');

        // У ремонта своя анкета: цена диагностики публикации не мешает,
        // а вот пустые услуги — мешают.
        $repair = Listing::factory()->repair()->create(['title' => 'Ремонт двигателей', 'services' => null, 'price' => null]);

        Livewire::test(EditListing::class, ['record' => $repair->id])
            ->assertSee('Не хватает для публикации: услуги.');
    });

    test('синк техники у опубликованного водителя переиздаёт вектор поиска', function () {
        $driver = Listing::factory()->driver()->published()->create(['title' => 'Машинист экскаватора']);
        $category = categoryNamed('Экскаватор');
        Queue::fake();

        // Пивот не трогает атрибуты модели и не будит её saved-хук —
        // вектор переиздаёт сама страница редактирования.
        Livewire::test(EditListing::class, ['record' => $driver->id])
            ->fillForm(['machine_categories' => [$category->id]])
            ->call('save')
            ->assertHasNoFormErrors();

        expect($driver->machineCategories()->pluck('categories.id')->all())->toBe([$category->id]);
        Queue::assertPushed(GenerateListingEmbedding::class);
    });
});

describe('документ водителя и галочка проверки', function () {
    beforeEach(fn () => $this->actingAs(User::factory()->create()));

    test('оператор открывает снимок документа', function () {
        Storage::fake('local');
        $listing = Listing::factory()->driver()->create();
        Storage::disk('local')->put("listings/{$listing->id}/documents/doc.jpg", 'JPEG');
        ListingMedia::create([
            'listing_id' => $listing->id, 'type' => ListingMediaType::Document,
            'disk' => 'local', 'path' => "listings/{$listing->id}/documents/doc.jpg",
        ]);

        $this->get(route('moderation.listings.document', $listing))->assertSuccessful();
    });

    test('у объявления без документа снимка нет — 404', function () {
        $listing = Listing::factory()->driver()->create();

        $this->get(route('moderation.listings.document', $listing))->assertNotFound();
    });

    test('ссылка «Открыть документ» видна только при наличии документа', function () {
        $withDocument = Listing::factory()->driver()->create();
        ListingMedia::create([
            'listing_id' => $withDocument->id, 'type' => ListingMediaType::Document,
            'disk' => 'local', 'path' => "listings/{$withDocument->id}/documents/doc.jpg",
        ]);

        Livewire::test(EditListing::class, ['record' => $withDocument->id])
            ->assertSee('Открыть документ');

        Livewire::test(EditListing::class, ['record' => Listing::factory()->driver()->create()->id])
            ->assertDontSee('Открыть документ');
    });

    test('галочка проверки фиксирует оператора и время', function () {
        $this->freezeTime();
        $operator = User::factory()->create();
        $this->actingAs($operator);
        $listing = Listing::factory()->driver()->create();

        Livewire::test(EditListing::class, ['record' => $listing->id])
            ->assertFormSet(['document_verified' => false])
            ->fillForm(['document_verified' => true])
            ->call('save')
            ->assertHasNoFormErrors();

        expect($listing->refresh())
            ->document_verified_at->toDateTimeString()->toBe(now()->toDateTimeString())
            ->document_verified_by->toBe($operator->id);
    });

    test('снятие галочки очищает отметку проверки', function () {
        $listing = Listing::factory()->driver()->create([
            'document_verified_at' => now(),
            'document_verified_by' => User::factory()->create()->id,
        ]);

        Livewire::test(EditListing::class, ['record' => $listing->id])
            ->assertFormSet(['document_verified' => true])
            ->fillForm(['document_verified' => false])
            ->call('save')
            ->assertHasNoFormErrors();

        expect($listing->refresh())
            ->document_verified_at->toBeNull()
            ->document_verified_by->toBeNull();
    });

    test('повторное сохранение с уже стоящей галочкой не переписывает след проверки', function () {
        $verifier = User::factory()->create();
        $verifiedAt = now()->subDay()->startOfSecond();
        $listing = Listing::factory()->driver()->create([
            'document_verified_at' => $verifiedAt,
            'document_verified_by' => $verifier->id,
        ]);

        Livewire::test(EditListing::class, ['record' => $listing->id])
            ->fillForm(['title' => 'Машинист экскаватора'])
            ->call('save')
            ->assertHasNoFormErrors();

        expect($listing->refresh())
            ->document_verified_at->toDateTimeString()->toBe($verifiedAt->toDateTimeString())
            ->document_verified_by->toBe($verifier->id);
    });
});

describe('таблица и поиск по виду', function () {
    beforeEach(fn () => $this->actingAs(User::factory()->create()));

    test('таблица показывает вид бейджем и фильтрует по нему', function () {
        Listing::factory()->create();
        $repair = Listing::factory()->repair()->create();

        Livewire::test(ListListings::class)
            ->assertSee('Аренда спецтехники')
            ->assertSee('Ремонт спецтехники')
            ->filterTable('kind', ListingKind::Repair->value)
            ->assertCanSeeTableRecords([$repair])
            ->assertCountTableRecords(1);
    });

    test('глобальный поиск находит мастера и водителя по имени', function () {
        expect(ListingResource::getGloballySearchableAttributes())->toContain('person_name');
    });

    test('техника вне справочника видна в таблице словами водителя', function () {
        // Пишется в нижнем регистре — хранится с заглавной, как категории.
        $driver = Listing::factory()->driver()->create(['unlisted_machinery' => 'автобус']);
        $rental = Listing::factory()->create();

        // Бейдж-предупреждение: у такого объявления оператору есть работа
        // до публикации — завести категорию. Колонку можно спрятать, но
        // по умолчанию она на экране.
        Livewire::test(ListListings::class)
            ->assertCanRenderTableColumn('unlisted_machinery')
            ->assertTableColumnExists('unlisted_machinery', fn (TextColumn $column): bool => $column->getLabel() === 'Техника вне справочника'
                && $column->isBadge()
                && $column->getColor('Автобус') === 'warning'
                && $column->isToggleable()
                && ! $column->isToggledHiddenByDefault()
                && $column->getPlaceholder() === '—')
            ->assertTableColumnStateSet('unlisted_machinery', 'Автобус', $driver)
            ->assertTableColumnStateSet('unlisted_machinery', null, $rental)
            ->assertSee('Автобус')
            // Оператор, ищущий «автобус» в очереди, находит такое объявление.
            ->searchTable('автобус')
            ->assertCanSeeTableRecords([$driver])
            ->assertCanNotSeeTableRecords([$rental]);
    });
});
