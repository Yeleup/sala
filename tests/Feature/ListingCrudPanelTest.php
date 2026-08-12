<?php

use App\Enums\ListingMediaType;
use App\Enums\ListingOrigin;
use App\Enums\ListingStatus;
use App\Filament\Resources\Listings\ListingResource;
use App\Filament\Resources\Listings\Pages\CreateListing;
use App\Filament\Resources\Listings\Pages\EditListing;
use App\Filament\Resources\Listings\Pages\ListListings;
use App\Filament\Resources\Listings\Schemas\ListingForm;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Contact;
use App\Models\CustomerRequest;
use App\Models\Listing;
use App\Models\ListingMedia;
use App\Models\Location;
use App\Models\User;
use App\Services\DereuMessenger;
use Filament\Actions\ActionGroup;
use Filament\Actions\Testing\TestAction;
use Filament\Schemas\Components\Component;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Enums\RecordActionsPosition;
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
        ->fillForm(['contact_id' => Contact::factory()->create()->id])
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
        ->callAction(TestAction::make('publish')->table($listing))
        ->assertNotified('Объявление опубликовано');

    $listing->refresh();
    expect($listing->status)->toBe(ListingStatus::Published)
        ->and($listing->expires_at->toDateTimeString())->toBe(now()->addDays(30)->toDateTimeString());
});

test('пока полей не хватает, публикация недоступна', function () {
    $incomplete = Listing::factory()->create(['title' => null]);

    Livewire::test(ListListings::class)
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
            ->callAction(
                TestAction::make('createOption')->schemaComponent('category_id'),
                ['name' => 'Ямобур'],
            );

        $category = Category::sole();
        expect($category->name)->toBe('Ямобур');
        $component->assertFormSet(['category_id' => $category->id]);
    });

    test('модалка предупреждает о похожей категории, но завести не мешает', function () {
        categoryNamed('Экскаватор');

        $component = Livewire::test(CreateListing::class)
            ->mountAction(TestAction::make('createOption')->schemaComponent('category_id'))
            ->fillForm(['name' => 'Эксковатор'])
            ->assertSchemaComponentExists('name', checkComponentUsing: fn (Component $field): bool => str_contains(
                helperTextOf($field),
                'Уже есть похожие: Экскаватор',
            ));

        // Предупреждение, а не запрет: «Автокран» и «Автовышка» тоже похожи,
        // а различить их может только оператор на звонке.
        $component
            ->fillForm(['name' => 'Экскаватор-погрузчик'])
            ->callMountedAction()
            ->assertHasNoActionErrors();

        expect(Category::pluck('name')->all())->toBe(['Экскаватор', 'Экскаватор-погрузчик']);
    });

    test('модалка предупреждает о похожей марке', function () {
        brandNamed('Hitachi');

        Livewire::test(CreateListing::class)
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
            ->callAction(
                TestAction::make('createOption')->schemaComponent('category_id'),
                ['name' => 'Автокран'],
            )
            ->assertHasActionErrors(['name']);

        expect(Category::count())->toBe(1);
    });

    test('марка заводится из формы и сразу подставляется', function () {
        $component = Livewire::test(CreateListing::class)
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
            ->callAction(TestAction::make('createOption')->schemaComponent($component), $data)
            ->assertHasNoActionErrors();

        expect($model::where('name', $name)->exists())->toBeTrue();
    })->with([
        'категория' => ['category_id', ['name' => 'Ямобур'], Category::class, 'Ямобур'],
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

    test('известный номер выбирает существующего поставщика, а не упирается в ошибку', function (bool $onEditPage) {
        $existing = Contact::factory()->create(['phone' => '77012345678', 'display_name' => 'ТОО «СтройКран»']);

        $component = $onEditPage
            ? Livewire::test(EditListing::class, ['record' => Listing::factory()->create()->id])
            : Livewire::test(CreateListing::class);

        $contactsBefore = Contact::count();

        // Номер — опознавательная запись контакта: продиктованный как
        // «8 701…» он должен привести к тому же поставщику.
        $component
            ->callAction(
                TestAction::make('createOption')->schemaComponent('contact_id'),
                ['phone' => '8 701 234 56 78'],
            )
            ->assertHasNoActionErrors()
            ->assertNotified('Выбран существующий поставщик')
            ->assertFormSet(['contact_id' => $existing->id]);

        expect(Contact::count())->toBe($contactsBefore);
    })->with([
        'на создании' => [false],
        'на редактировании' => [true],
    ]);

    test('в окне поставщика можно выбрать найденного из списка, ничего не заполняя', function () {
        $existing = Contact::factory()->create(['phone' => '77012345678', 'display_name' => 'ТОО «СтройКран»']);

        // Выбор из списка снимает требование заполнить телефон: поля
        // создания скрыты, а скрытое Filament не валидирует.
        Livewire::test(CreateListing::class)
            ->callAction(
                TestAction::make('createOption')->schemaComponent('contact_id'),
                ['existing_contact_id' => $existing->id],
            )
            ->assertHasNoActionErrors()
            ->assertFormSet(['contact_id' => $existing->id]);

        expect(Contact::count())->toBe(1);
    });

    test('поиск поставщика находит по номеру в любом написании и по имени', function () {
        $existing = Contact::factory()->create(['phone' => '77012345678', 'display_name' => 'ТОО «СтройКран»']);
        Contact::factory()->create(['phone' => '77770001122', 'display_name' => 'Другой']);

        // Один и тот же поиск питает и поле «Поставщик», и список в окне.
        expect(ListingForm::supplierOptions('8 701'))->toBe([$existing->id => '77012345678 (ТОО «СтройКран»)'])
            ->and(ListingForm::supplierOptions('+7 701'))->toHaveKey($existing->id)
            ->and(ListingForm::supplierOptions('стройкран'))->toHaveKey($existing->id)
            ->and(ListingForm::supplierOptions('8 999'))->toBe([]);
    });

    test('незнакомый номер по-прежнему заводит нового поставщика', function () {
        Livewire::test(CreateListing::class)
            ->callAction(
                TestAction::make('createOption')->schemaComponent('contact_id'),
                ['phone' => '+7 (777) 000-11-22', 'profile_name' => 'Асхат'],
            )
            ->assertHasNoActionErrors();

        expect(Contact::sole())
            ->phone->toBe('77770001122')
            ->profile_name->toBe('Асхат');
    });

    test('дубль справочника со страницы редактирования тоже отклоняется понятной ошибкой', function () {
        categoryNamed('Автокран');
        $listing = Listing::factory()->create();

        Livewire::test(EditListing::class, ['record' => $listing->id])
            ->callAction(
                TestAction::make('createOption')->schemaComponent('category_id'),
                ['name' => 'Автокран'],
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

test('без поставщика объявление не создаётся', function () {
    Livewire::test(CreateListing::class)
        ->fillForm(['description' => 'Без обязательных полей'])
        ->call('create')
        ->assertHasFormErrors(['contact_id']);

    expect(Listing::count())->toBe(0);
});

test('у объявления нет страницы просмотра — оно открывается сразу на редактирование', function () {
    $listing = Listing::factory()->create();

    expect(ListingResource::getPages())->not->toHaveKey('view');

    $this->get(ListingResource::getUrl('edit', ['record' => $listing]))->assertOk();

    Livewire::test(ListListings::class)
        ->assertActionDoesNotExist(TestAction::make('view')->table($listing));
});

test('все действия строки собраны в одно меню и ни одно не потеряно', function () {
    $table = Livewire::test(ListListings::class)->instance()->getTable();

    $recordActions = $table->getRecordActions();

    // The menu opens the row: as the last column it stayed behind the
    // horizontal scroll, which is exactly what the operator complained about.
    expect($table->getRecordActionsPosition())->toBe(RecordActionsPosition::BeforeCells)
        ->and($recordActions)->toHaveCount(1)
        ->and($recordActions[0])->toBeInstanceOf(ActionGroup::class)
        ->and(array_keys($recordActions[0]->getFlatActions()))
        ->toEqualCanonicalizing([
            'edit',
            'preview',
            'publish',
            'submitForModeration',
            'approve',
            'reject',
            'renew',
            'archive',
            'delete',
        ]);
});

test('любую колонку можно выключить, а редкие выключены сразу', function () {
    $columns = Livewire::test(ListListings::class)->instance()->getTable()->getColumns();

    expect(collect($columns)->reject(fn ($column) => $column->isToggleable())->keys())->toBeEmpty()
        ->and(collect($columns)->filter(fn ($column) => $column->isToggledHiddenByDefault())->keys()->all())
        ->toEqualCanonicalizing(['id', 'brand.name', 'person_name', 'origin', 'created_at']);
});

test('статус показан иконкой, а подсказка называет его и срок актуальности', function () {
    $published = Listing::factory()->published()->create(['expires_at' => now()->addDays(30)]);
    $draft = Listing::factory()->create(['status' => ListingStatus::Draft, 'expires_at' => null]);

    $column = Livewire::test(ListListings::class)->instance()->getTable()->getColumn('status');

    expect($column)->toBeInstanceOf(IconColumn::class)
        ->and($column->record($published)->getIcon(ListingStatus::Published))
        ->toBe(Heroicon::OutlinedCheckCircle)
        ->and($column->record($published)->getTooltip())
        ->toBe('Опубликовано, актуально до '.$published->expires_at->format('d.m.Y H:i'))
        // A draft never expires, so there is nothing to add to its name.
        ->and($column->record($draft)->getTooltip())->toBe('Черновик');

    // Five states the operator now tells apart by shape, so no two may share
    // an icon.
    $icons = collect(ListingStatus::cases())
        ->mapWithKeys(fn (ListingStatus $status): array => [$status->value => $column->getIcon($status)]);

    expect($icons->filter())->toHaveCount(5)
        ->and($icons->unique())->toHaveCount(5);
});

test('выключенная колонка остаётся в поиске', function () {
    $matching = Listing::factory()->create([
        'brand_id' => Brand::factory()->create(['name' => 'Komatsu'])->id,
    ]);
    Listing::factory()->create(['brand_id' => Brand::factory()->create(['name' => 'Hitachi'])->id]);

    // «Марка» is switched off by default, and the operator still has to be
    // able to find a listing by it.
    Livewire::test(ListListings::class)
        ->searchTable('Komatsu')
        ->assertCanSeeTableRecords([$matching])
        ->assertCountTableRecords(1);
});

test('клик по строке по-прежнему открывает объявление на редактирование', function () {
    $listing = Listing::factory()->create();

    $recordUrl = Livewire::test(ListListings::class)
        ->instance()
        ->getTable()
        ->getRecordUrl($listing);

    expect($recordUrl)->toBe(ListingResource::getUrl('edit', ['record' => $listing]));
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
