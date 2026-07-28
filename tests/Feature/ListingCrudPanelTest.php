<?php

use App\Enums\ListingMediaType;
use App\Enums\ListingOrigin;
use App\Enums\ListingStatus;
use App\Enums\ListingType;
use App\Filament\Resources\Listings\ListingResource;
use App\Filament\Resources\Listings\Pages\CreateListing;
use App\Filament\Resources\Listings\Pages\EditListing;
use App\Filament\Resources\Listings\Pages\ListListings;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Contact;
use App\Models\CustomerRequest;
use App\Models\Listing;
use App\Models\ListingMedia;
use App\Models\Location;
use App\Models\User;
use App\Services\DereuMessenger;
use Filament\Actions\Testing\TestAction;
use Filament\Schemas\Components\Component;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Ai\Embeddings;
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
            'title' => 'Аренда автокрана 25 т',
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
        ->title->toBe('Аренда автокрана 25 т')
        ->category->name->toBe('Автокран')
        ->location->name->toBe('г.Шымкент');
});

test('объявление, заведённое оператором, помечено источником и автором', function () {
    $operator = User::factory()->create();
    $this->actingAs($operator);

    Livewire::test(CreateListing::class)
        ->fillForm([
            'contact_id' => Contact::factory()->create()->id,
            'type' => ListingType::Equipment->value,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Listing::sole())
        ->origin->toBe(ListingOrigin::Operator)
        ->created_by_user_id->toBe($operator->id);
});

test('оператор публикует своё объявление одной кнопкой, без уведомления поставщику', function () {
    $this->freezeTime();
    Embeddings::fake();
    // Любой вызов мессенджера здесь — ошибка: объявление согласовано по
    // телефону, уведомление о нём не уходит.
    $this->mock(DereuMessenger::class);
    $listing = Listing::factory()->publishable()->create();

    Livewire::test(ListListings::class)
        ->filterTable('status', ListingStatus::Draft->value)
        ->callAction(TestAction::make('publish')->table($listing))
        ->assertNotified('Объявление опубликовано');

    $listing->refresh();
    expect($listing->status)->toBe(ListingStatus::Published)
        ->and($listing->expires_at->toDateTimeString())->toBe(now()->addDays(30)->toDateTimeString());
});

test('пока полей не хватает, публикация недоступна', function () {
    $incomplete = Listing::factory()->create(['title' => null]);

    Livewire::test(ListListings::class)
        ->filterTable('status', ListingStatus::Draft->value)
        ->assertActionDisabled(TestAction::make('publish')->table($incomplete));

    expect($incomplete->refresh()->status)->toBe(ListingStatus::Draft);
});

test('форма сама говорит, чего ещё спросить у клиента для публикации', function () {
    $incomplete = Listing::factory()->create(['title' => null, 'price' => null]);

    Livewire::test(EditListing::class, ['record' => $incomplete->id])
        ->assertSee('Не хватает для публикации: название, цена.');

    Livewire::test(EditListing::class, ['record' => Listing::factory()->publishable()->create()->id])
        ->assertSee('Все поля заполнены — объявление можно публиковать.');
});

test('подсказка о нехватке полей пересчитывается прямо во время набора', function () {
    $listing = Listing::factory()->create(['title' => null]);

    Livewire::test(EditListing::class, ['record' => $listing->id])
        ->assertSee('Не хватает для публикации: название.')
        // Ничего ещё не сохранено — подсказка идёт по состоянию формы.
        ->fillForm(['title' => 'Аренда автокрана 25 т'])
        ->assertSee('Все поля заполнены — объявление можно публиковать.');

    expect($listing->refresh()->title)->toBeNull();
});

test('опубликованное объявление публиковать повторно нечем', function () {
    $listing = Listing::factory()->published()->create();

    Livewire::test(EditListing::class, ['record' => $listing->id])
        ->assertActionHidden('publish');
});

test('«создать ещё» оставляет поставщика и общие поля, но очищает название и описание', function () {
    $supplier = Contact::factory()->create();
    $category = Category::factory()->create(['name' => 'Экскаватор']);
    $location = locationNamed('г.Шымкент');

    $component = Livewire::test(CreateListing::class)
        ->fillForm([
            'contact_id' => $supplier->id,
            'type' => ListingType::Equipment->value,
            'title' => 'Экскаватор Hitachi ZX200',
            'category_id' => $category->id,
            'description' => 'Гусеничный, ковш 1 куб',
            'location_id' => $location->id,
            'price' => '15000 тг/ч',
        ])
        ->call('create', another: true)
        ->assertHasNoFormErrors();

    // Общее у парка одной компании остаётся набранным...
    $component->assertFormSet([
        'contact_id' => $supplier->id,
        'type' => ListingType::Equipment,
        'category_id' => $category->id,
        'location_id' => $location->id,
        'price' => '15000 тг/ч',
    ]);

    // ...а то, чем единицы техники различаются, — нет.
    $component->assertFormSet(['title' => null, 'description' => null]);

    // Перенесённого хватает, чтобы вторая единица техники сохранилась
    // с одним только названием.
    $component
        ->fillForm(['title' => 'Экскаватор Hitachi ZX330'])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Listing::pluck('title')->all())->toBe(['Экскаватор Hitachi ZX200', 'Экскаватор Hitachi ZX330'])
        ->and(Listing::pluck('contact_id')->unique()->all())->toBe([$supplier->id])
        ->and(Listing::pluck('price')->unique()->all())->toBe(['15000 тг/ч']);
});

test('новый поставщик заводится прямо из формы объявления', function () {
    Livewire::test(CreateListing::class)
        ->callAction(
            TestAction::make('createOption')->schemaComponent('contact_id'),
            ['phone' => '8 701 234 56 78', 'profile_name' => 'Асхат'],
        );

    // Номер сохранён в том виде, в котором его знает WhatsApp.
    expect(Contact::sole())
        ->phone->toBe('77012345678')
        ->profile_name->toBe('Асхат');
});

test('место показывается с цепочкой родителей — одноимённые различимы', function () {
    $oblast = locationNamed('область Абай');
    $district = locationNamed('Абайский район', $oblast);
    $listing = Listing::factory()->create(['location_id' => $district->id]);

    // Район не входит в верх справочника, которым открывается пустое поле,
    // — подпись выбранному месту всё равно должна найтись.
    Livewire::test(EditListing::class, ['record' => $listing->id])
        ->assertSee('Абайский район, область Абай');
});

test('пустое поле локации открывается верхом справочника КАТО', function () {
    locationNamed('г.Шымкент');
    locationNamed('область Абай');

    Livewire::test(CreateListing::class)
        ->assertSee('г.Шымкент')
        ->assertSee('область Абай');
});

describe('справочники пополняются прямо из формы объявления', function () {
    test('категория заводится из формы и сразу подставляется', function () {
        $component = Livewire::test(CreateListing::class)
            ->fillForm(['type' => ListingType::Equipment->value])
            ->callAction(
                TestAction::make('createOption')->schemaComponent('category_id'),
                ['name' => 'Ямобур', 'type' => ListingType::Equipment->value],
            );

        $category = Category::sole();
        expect($category)->name->toBe('Ямобур')->type->toBe(ListingType::Equipment);
        $component->assertFormSet(['category_id' => $category->id]);
    });

    test('заведённая категория-услуга переводит объявление в услуги и снимает марку', function () {
        $component = Livewire::test(CreateListing::class)
            ->fillForm([
                'type' => ListingType::Equipment->value,
                'brand_id' => brandNamed('Hitachi')->id,
            ])
            ->callAction(
                TestAction::make('createOption')->schemaComponent('category_id'),
                ['name' => 'Сварщик', 'type' => ListingType::Service->value],
            );

        // Справочник типизирует категорию, а категория — объявление: иначе
        // у объявления остался бы тип, которому его же категория противоречит.
        $component->assertFormSet([
            'type' => ListingType::Service,
            'brand_id' => null,
            'category_id' => Category::sole()->id,
        ]);
    });

    test('модалка предупреждает о похожей категории, но завести не мешает', function () {
        categoryNamed('Экскаватор');

        $component = Livewire::test(CreateListing::class)
            ->fillForm(['type' => ListingType::Equipment->value])
            ->mountAction(TestAction::make('createOption')->schemaComponent('category_id'))
            ->fillForm(['name' => 'Эксковатор'])
            ->assertSchemaComponentExists('name', checkComponentUsing: fn (Component $field): bool => str_contains(
                helperTextOf($field),
                'Уже есть похожие: Экскаватор',
            ));

        // Предупреждение, а не запрет: «Автокран» и «Автовышка» тоже похожи,
        // а различить их может только оператор на звонке.
        $component
            ->fillForm(['name' => 'Экскаватор-погрузчик', 'type' => ListingType::Equipment->value])
            ->callMountedAction()
            ->assertHasNoActionErrors();

        expect(Category::pluck('name')->all())->toBe(['Экскаватор', 'Экскаватор-погрузчик']);
    });

    test('модалка предупреждает о похожей марке', function () {
        brandNamed('Hitachi');

        Livewire::test(CreateListing::class)
            ->fillForm(['type' => ListingType::Equipment->value])
            ->mountAction(TestAction::make('createOption')->schemaComponent('brand_id'))
            ->fillForm(['name' => 'HITACHI'])
            ->assertSchemaComponentExists('name', checkComponentUsing: fn (Component $field): bool => str_contains(
                helperTextOf($field),
                'Уже есть похожие: Hitachi',
            ));
    });

    test('о похожем месте предупреждает только внутри того же родителя', function () {
        $shymkent = locationNamed('г.Шымкент');
        $almaty = locationNamed('г.Алматы');
        locationNamed('мкр Нурсат', $shymkent);

        Livewire::test(CreateListing::class)
            ->mountAction(TestAction::make('createOption')->schemaComponent('location_id'))
            ->fillForm(['parent_id' => $shymkent->id, 'name' => 'микрорайон Нурсат'])
            ->assertSchemaComponentExists('name', checkComponentUsing: fn (Component $field): bool => str_contains(
                helperTextOf($field),
                'Уже есть похожие: мкр Нурсат',
            ))
            // Тёзки в разных местах страны — норма для КАТО, предупреждать не о чем.
            ->fillForm(['parent_id' => $almaty->id, 'name' => 'микрорайон Нурсат'])
            ->assertSchemaComponentExists('name', checkComponentUsing: fn (Component $field): bool => helperTextOf($field) === '');
    });

    test('дубль категории из формы объявления не заводится', function () {
        categoryNamed('Автокран');

        Livewire::test(CreateListing::class)
            ->fillForm(['type' => ListingType::Equipment->value])
            ->callAction(
                TestAction::make('createOption')->schemaComponent('category_id'),
                ['name' => 'Автокран', 'type' => ListingType::Equipment->value],
            )
            ->assertHasActionErrors(['name']);

        expect(Category::count())->toBe(1);
    });

    test('марка заводится из формы и сразу подставляется', function () {
        $component = Livewire::test(CreateListing::class)
            ->fillForm(['type' => ListingType::Equipment->value])
            ->callAction(
                TestAction::make('createOption')->schemaComponent('brand_id'),
                ['name' => 'Komatsu'],
            );

        $brand = Brand::sole();
        expect($brand->name)->toBe('Komatsu');
        $component->assertFormSet(['brand_id' => $brand->id]);
    });

    test('место заводится внутрь существующего узла справочника', function () {
        $city = locationNamed('г.Шымкент');

        $component = Livewire::test(CreateListing::class)
            ->callAction(
                TestAction::make('createOption')->schemaComponent('location_id'),
                ['name' => 'мкр Нурсат', 'parent_id' => $city->id],
            );

        $created = Location::where('name', 'мкр Нурсат')->sole();
        expect($created->parent_id)->toBe($city->id)
            // Поиск заказчика идёт по поддереву — путь и уровень должны
            // достроиться, иначе место выпадет из выдачи по городу.
            ->and($created->path)->toBe($city->fresh()->path.$created->id.'/')
            ->and($created->depth)->toBe(1);
        $component->assertFormSet(['location_id' => $created->id]);
    });

    test('место без родителя из формы объявления не заводится', function () {
        // Область или город республиканского значения на бегу не создаются —
        // такой узел заводится на странице справочника осознанно.
        Livewire::test(CreateListing::class)
            ->callAction(
                TestAction::make('createOption')->schemaComponent('location_id'),
                ['name' => 'область Новая'],
            )
            ->assertHasActionErrors(['parent_id']);

        expect(Location::count())->toBe(0);
    });

    // На странице редактирования вокруг модалки есть запись — объявление.
    // Проверка уникальности справочника не должна её подхватывать: иначе
    // из запроса по справочнику исключается строка по listings.id, и
    // вместо понятной ошибки оператор получает сломанный SQL.
    test('категория и марка заводятся и со страницы редактирования объявления', function (string $component, array $data, string $model, string $name) {
        $listing = Listing::factory()->create();

        Livewire::test(EditListing::class, ['record' => $listing->id])
            ->fillForm(['type' => ListingType::Equipment->value])
            ->callAction(TestAction::make('createOption')->schemaComponent($component), $data)
            ->assertHasNoActionErrors();

        expect($model::where('name', $name)->exists())->toBeTrue();
    })->with([
        'категория' => ['category_id', ['name' => 'Ямобур', 'type' => 'equipment'], Category::class, 'Ямобур'],
        'марка' => ['brand_id', ['name' => 'Komatsu'], Brand::class, 'Komatsu'],
    ]);

    test('место заводится и со страницы редактирования объявления', function () {
        $city = locationNamed('г.Шымкент');
        $listing = Listing::factory()->create();

        Livewire::test(EditListing::class, ['record' => $listing->id])
            ->callAction(
                TestAction::make('createOption')->schemaComponent('location_id'),
                ['name' => 'мкр Нурсат', 'parent_id' => $city->id],
            )
            ->assertHasNoActionErrors();

        expect(Location::where('name', 'мкр Нурсат')->sole()->parent_id)->toBe($city->id);
    });

    test('поставщик заводится и со страницы редактирования, дубль номера назван', function () {
        Contact::factory()->create(['phone' => '77012345678', 'display_name' => 'ТОО «СтройКран»']);
        $listing = Listing::factory()->create();

        Livewire::test(EditListing::class, ['record' => $listing->id])
            ->callAction(
                TestAction::make('createOption')->schemaComponent('contact_id'),
                ['phone' => '8 701 234 56 78'],
            )
            ->assertHasActionErrors(['phone' => 'Контакт с таким номером уже есть — «ТОО «СтройКран»».']);
    });

    test('дубль справочника со страницы редактирования тоже отклоняется понятной ошибкой', function () {
        categoryNamed('Автокран');
        $listing = Listing::factory()->create();

        Livewire::test(EditListing::class, ['record' => $listing->id])
            ->fillForm(['type' => ListingType::Equipment->value])
            ->callAction(
                TestAction::make('createOption')->schemaComponent('category_id'),
                ['name' => 'Автокран', 'type' => ListingType::Equipment->value],
            )
            ->assertHasActionErrors(['name']);
    });

    test('одноимённое место у другого родителя заводится, у того же — нет', function () {
        $shymkent = locationNamed('г.Шымкент');
        $almaty = locationNamed('г.Алматы');
        locationNamed('Абайский район', $shymkent);

        // Тёзки по всей стране — норма для КАТО, имя уникально в пределах родителя.
        Livewire::test(CreateListing::class)
            ->callAction(
                TestAction::make('createOption')->schemaComponent('location_id'),
                ['name' => 'Абайский район', 'parent_id' => $almaty->id],
            )
            ->assertHasNoActionErrors();

        Livewire::test(CreateListing::class)
            ->callAction(
                TestAction::make('createOption')->schemaComponent('location_id'),
                ['name' => 'Абайский район', 'parent_id' => $shymkent->id],
            )
            ->assertHasActionErrors(['name']);

        expect(Location::where('name', 'Абайский район')->count())->toBe(2);
    });
});

test('без поставщика и типа объявление не создаётся', function () {
    Livewire::test(CreateListing::class)
        ->fillForm(['description' => 'Без обязательных полей'])
        ->call('create')
        ->assertHasFormErrors(['contact_id', 'type']);

    expect(Listing::count())->toBe(0);
});

test('у объявления нет страницы просмотра — оно открывается сразу на редактирование', function () {
    $listing = Listing::factory()->create();

    expect(ListingResource::getPages())->not->toHaveKey('view');

    $this->get(ListingResource::getUrl('edit', ['record' => $listing]))->assertOk();

    Livewire::test(ListListings::class)
        ->filterTable('status', ListingStatus::Draft->value)
        ->assertActionDoesNotExist(TestAction::make('view')->table($listing));
});

test('оператор редактирует бизнес-поля объявления', function () {
    $listing = Listing::factory()->create();
    $category = Category::factory()->create(['name' => 'Экскаватор']);

    Livewire::test(EditListing::class, ['record' => $listing->id])
        ->fillForm([
            'title' => 'Аренда экскаватора',
            'category_id' => $category->id,
            'description' => 'Гусеничный экскаватор',
            'price' => '15000 тг/ч',
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($listing->refresh())
        ->title->toBe('Аренда экскаватора')
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
