<?php

use App\Enums\LicenceType;
use App\Enums\ListingMediaType;
use App\Enums\ListingStatus;
use App\Filament\Resources\Categories\Pages\ListCategories;
use App\Filament\Resources\Listings\Pages\CreateListing;
use App\Filament\Resources\Listings\Pages\EditListing;
use App\Filament\Resources\Listings\Pages\ListListings;
use App\Models\Category;
use App\Models\Contact;
use App\Models\Listing;
use App\Models\ListingMedia;
use App\Models\User;
use App\Services\Ai\CtaLinkBuilder;
use App\Services\Ai\ListingEmbeddings;
use App\Services\Ai\ListingMatcher;
use Filament\Actions\Testing\TestAction;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Component;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Ai\Embeddings;
use Livewire\Livewire;

uses(RefreshDatabase::class);

// Новая техника, которую ИИ завёл в справочнике со слов поставщика, не
// видна нигде, пока оператор не одобрит объявление с ней; отклонение
// уносит её из справочника, если больше ничто на неё не ссылается.
beforeEach(fn () => Embeddings::fake());

/**
 * Объявление аренды на модерации с новой (неутверждённой) категорией.
 */
function pendingRentalWithNewCategory(string $name = 'Автобус'): Listing
{
    return Listing::factory()->pendingModeration()->create([
        'category_id' => Category::factory()->unapproved()->create(['name' => $name])->id,
    ]);
}

/**
 * Объявление водителя на модерации с техникой из справочника и новой.
 */
function pendingDriverWithNewMachinery(Category $new): Listing
{
    $listing = Listing::factory()->driver()->pendingModeration()->create(['unlisted_machinery' => null]);
    $listing->machineCategories()->attach([categoryNamed('Экскаватор')->id, $new->id]);

    return $listing;
}

describe('справочник', function () {
    test('категории, заведённые оператором и существовавшие раньше, утверждены', function () {
        $category = Category::query()->create(['name' => 'Автокран'])->refresh();

        expect($category->isApproved())->toBeTrue()
            ->and(categoryNamed('Экскаватор')->refresh()->isApproved())->toBeTrue();
    });

    test('ИИ не заводит дубль: имя сверяется без учёта регистра и пробелов, в том числе с новой записью', function () {
        $approved = categoryNamed('Экскаватор');
        $new = Category::findOrCreateUnapproved('  автобус ');

        expect($new->name)->toBe('Автобус')
            ->and($new->isApproved())->toBeFalse()
            ->and(Category::findOrCreateUnapproved('ЭКСКАВАТОР')->is($approved))->toBeTrue()
            ->and(Category::findOrCreateUnapproved('Автобус')->is($new))->toBeTrue()
            ->and(Category::count())->toBe(2);
    });
});

describe('гонка двух поставщиков', function () {
    test('база не пропускает вторую запись, отличающуюся только регистром', function () {
        Category::factory()->unapproved()->create(['name' => 'Автобус']);

        expect(fn () => DB::transaction(fn () => DB::table('categories')->insert(['name' => 'АВТОБУС'])))
            ->toThrow(UniqueConstraintViolationException::class)
            ->and(Category::count())->toBe(1);
    });

    test('проигравший вставку поставщик получает запись победителя, даже в другом регистре', function () {
        // Второе соединение — другой поставщик: оно вставляет «АВТОБУС» и
        // коммитит между проверкой по имени и вставкой первого. Первый ловит
        // нарушение уникальности без учёта регистра и берёт запись второго.
        config(['database.connections.race' => config('database.connections.'.config('database.default'))]);
        $race = DB::connection('race');
        $raced = false;

        Category::creating(function () use ($race, &$raced): void {
            if (! $raced) {
                $raced = true;
                $race->table('categories')->insert(['name' => 'АВТОБУС', 'approved_at' => null, 'created_at' => now(), 'updated_at' => now()]);
            }
        });

        try {
            $category = Category::findOrCreateUnapproved('Автобус');

            expect($raced)->toBeTrue()
                ->and($category->name)->toBe('АВТОБУС')
                ->and(Category::query()->whereRaw('lower(name) = ?', ['автобус'])->count())->toBe(1);
        } finally {
            $race->table('categories')->whereRaw('lower(name) = ?', ['автобус'])->delete();
            $race->disconnect();
        }
    });
});

describe('одобрение и публикация', function () {
    test('одобрение объявления утверждает его новую категорию', function () {
        $this->actingAs(User::factory()->create());
        $listing = pendingRentalWithNewCategory();

        Livewire::test(ListListings::class)
            ->callAction(TestAction::make('approve')->table($listing))
            ->assertNotified('Объявление опубликовано');

        expect($listing->refresh()->status)->toBe(ListingStatus::Published)
            ->and($listing->category->isApproved())->toBeTrue();
    });

    test('одобрение водителя утверждает новую технику, не трогая остальную', function () {
        $new = Category::factory()->unapproved()->create(['name' => 'Автобус']);
        $excavatorApprovedAt = categoryNamed('Экскаватор')->refresh()->approved_at;
        $listing = pendingDriverWithNewMachinery($new);

        $listing->approve();

        expect($new->refresh()->isApproved())->toBeTrue()
            ->and(categoryNamed('Экскаватор')->refresh()->approved_at->equalTo($excavatorApprovedAt))->toBeTrue();
    });

    test('оператор, публикуя своё объявление, утверждает и его новую категорию', function () {
        $listing = Listing::factory()->create([
            'status' => ListingStatus::Draft,
            'title' => 'Аренда автобуса',
            'category_id' => Category::factory()->unapproved()->create(['name' => 'Автобус'])->id,
        ]);

        $listing->publish();

        expect($listing->category->refresh()->isApproved())->toBeTrue();
    });

    test('одобрение одного объявления утверждает новую категорию и для второго с ней же', function () {
        $first = pendingRentalWithNewCategory();
        $second = Listing::factory()->pendingModeration()->create(['category_id' => $first->category_id]);

        $first->approve();

        expect($second->refresh()->category->isApproved())->toBeTrue()
            ->and($second->unapprovedCategories())->toBeEmpty();
    });
});

describe('отклонение', function () {
    test('отклонение удаляет новую категорию, если к ней больше ничего не привязано', function () {
        $this->actingAs(User::factory()->create());
        $listing = pendingRentalWithNewCategory();
        $categoryId = $listing->category_id;

        Livewire::test(ListListings::class)
            ->callAction(TestAction::make('reject')->table($listing), ['rejection_reason' => 'Не спецтехника'])
            ->assertNotified('Объявление отклонено');

        expect($listing->refresh()->status)->toBe(ListingStatus::Rejected)
            ->and($listing->category_id)->toBeNull()
            ->and(Category::find($categoryId))->toBeNull();
    });

    test('новая категория, привязанная к другому объявлению, после отклонения остаётся', function () {
        $listing = pendingRentalWithNewCategory();
        $other = Listing::factory()->pendingModeration()->create(['category_id' => $listing->category_id]);

        $listing->reject('Дубль');

        expect($other->refresh()->category)->not->toBeNull()
            ->and($other->category->name)->toBe('Автобус')
            ->and($other->category->isApproved())->toBeFalse()
            ->and($listing->refresh()->category_id)->toBeNull();
    });

    test('новая техника водителя при отклонении уходит в технику словами, справочная остаётся', function () {
        $new = Category::factory()->unapproved()->create(['name' => 'Автобус']);
        $other = Listing::factory()->driver()->create();
        $other->machineCategories()->attach($new);
        $listing = pendingDriverWithNewMachinery($new);
        $orphan = Category::factory()->unapproved()->create(['name' => 'Водовоз']);
        $listing->machineCategories()->attach($orphan);

        $listing->reject('Нет фото удостоверения');

        $listing->refresh();
        expect($listing->machineCategories->pluck('name')->all())->toBe(['Экскаватор'])
            ->and($listing->unlisted_machinery)->toBe('Автобус, Водовоз')
            ->and(Category::find($orphan->id))->toBeNull()
            ->and(Category::find($new->id))->not->toBeNull();
    });

    test('отклонение не трогает утверждённые категории объявления', function () {
        $listing = Listing::factory()->pendingModeration()->create(['category_id' => categoryNamed('Автокран')->id]);

        $listing->reject('Нет цены');

        expect($listing->refresh()->category->name)->toBe('Автокран');
    });

    test('удаление объявления уносит его осиротевшую новую категорию', function () {
        $listing = pendingRentalWithNewCategory();
        $categoryId = $listing->category_id;

        $listing->delete();

        expect(Category::find($categoryId))->toBeNull();
    });
});

describe('видимость до одобрения', function () {
    test('каталог заказчика не показывает новую категорию в фильтре и не фильтрует по ней', function () {
        categoryNamed('Автокран');
        $listing = pendingRentalWithNewCategory();
        $contact = Contact::factory()->create();
        $url = app(CtaLinkBuilder::class)->catalogUrl($contact);

        $this->get($url)->assertOk()->assertSee('Автокран')->assertDontSee('Автобус');
        $this->get($url.'&category_id='.$listing->category_id)->assertOk()->assertViewHas('filters', fn (array $filters): bool => $filters['category'] === null);

        $listing->approve();

        $this->get($url)->assertSee('Автобус');
        $this->get($url.'&category_id='.$listing->category_id)->assertViewHas('filters', fn (array $filters): bool => $filters['category']?->is($listing->category) === true);
    });

    test('веб-форма поставщика показывает свою новую технику отдельно, а в списке категорий — только утверждённые', function () {
        categoryNamed('Автокран');
        $own = Listing::factory()->create([
            'status' => ListingStatus::Draft,
            'category_id' => Category::factory()->unapproved()->create(['name' => 'Автобус'])->id,
        ]);
        $foreign = Category::factory()->unapproved()->create(['name' => 'Водовоз']);

        $this->get(app(CtaLinkBuilder::class)->editUrl($own))
            ->assertOk()
            ->assertViewHas('categories', fn ($categories): bool => $categories->pluck('name')->all() === ['Автокран'])
            ->assertSee('Новая техника')
            ->assertSee('— оставить новую технику —')
            ->assertDontSee('<option value="'.$own->category_id.'"', false)
            ->assertDontSee('Водовоз');

        expect($foreign->refresh()->isApproved())->toBeFalse();
    });

    test('веб-форма аренды: пустой выбор оставляет свою новую технику, чужую не принять, замена отпускает свою', function () {
        $own = Listing::factory()->create([
            'status' => ListingStatus::Draft,
            'category_id' => Category::factory()->unapproved()->create(['name' => 'Автобус'])->id,
        ]);
        $foreign = Category::factory()->unapproved()->create(['name' => 'Водовоз']);
        $updateUrl = app(CtaLinkBuilder::class)->updateUrl($own);
        $payload = [
            'title' => 'Аренда автобуса',
            'description' => 'Автобус с водителем',
            'location_id' => locationNamed('г.Шымкент')->id,
            'price' => '10000 тг/ч',
        ];

        $this->post($updateUrl, [...$payload, 'category_id' => $foreign->id])->assertSessionHasErrors('category_id');

        $this->post($updateUrl, [...$payload, 'category_id' => ''])->assertSessionHasNoErrors();
        expect($own->refresh()->status)->toBe(ListingStatus::PendingModeration)
            ->and($own->category->name)->toBe('Автобус');

        $own->update(['status' => ListingStatus::Draft]);
        $this->post($updateUrl, [...$payload, 'category_id' => categoryNamed('Автокран')->id])->assertSessionHasNoErrors();

        expect($own->refresh()->category->name)->toBe('Автокран')
            ->and(Category::query()->where('name', 'Автобус')->exists())->toBeFalse();
    });

    test('веб-форма водителя: своя новая техника — отдельным блоком, её можно оставить или снять, чужую не принять', function () {
        $new = Category::factory()->unapproved()->create(['name' => 'Автобус']);
        $foreign = Category::factory()->unapproved()->create(['name' => 'Водовоз']);
        $listing = Listing::factory()->driver()->create(['status' => ListingStatus::Draft, 'unlisted_machinery' => null]);
        $listing->machineCategories()->attach($new);
        ListingMedia::create(['listing_id' => $listing->id, 'type' => ListingMediaType::Document, 'disk' => 'local', 'path' => 'doc.jpg']);
        $updateUrl = app(CtaLinkBuilder::class)->updateUrl($listing);
        $payload = [
            'title' => 'Водитель автобуса',
            'person_name' => 'Серик',
            'licence_type' => LicenceType::DriverLicence->value,
            'experience_years' => 8,
            'location_id' => locationNamed('г.Шымкент')->id,
            'travels_to_other_cities' => '1',
        ];

        $this->get(app(CtaLinkBuilder::class)->editUrl($listing))
            ->assertOk()
            ->assertSee('name="keep_new_machinery[]" value="'.$new->id.'"', false)
            ->assertDontSee('name="machine_categories[]" value="'.$new->id.'"', false)
            ->assertDontSee('Водовоз');

        $this->post($updateUrl, [...$payload, 'machine_categories' => [$foreign->id]])->assertSessionHasErrors('machine_categories.0');
        $this->post($updateUrl, [...$payload, 'keep_new_machinery' => [$foreign->id]])->assertSessionHasErrors('keep_new_machinery.0');

        $this->post($updateUrl, [...$payload, 'keep_new_machinery' => [$new->id]])->assertSessionHasNoErrors();
        expect($listing->refresh()->machineCategories->pluck('name')->all())->toBe(['Автобус']);

        $listing->update(['status' => ListingStatus::Draft]);
        $this->post($updateUrl, [...$payload, 'machine_categories' => [categoryNamed('Экскаватор')->id]])->assertSessionHasNoErrors();

        expect($listing->refresh()->machineCategories->pluck('name')->all())->toBe(['Экскаватор'])
            ->and(Category::find($new->id))->toBeNull();
    });

    test('исправление опечаток поиска не опирается на новую категорию', function () {
        categoryNamed('Экскаватор');
        Category::factory()->unapproved()->create(['name' => 'Автобус']);

        $matcher = new class(app(ListingEmbeddings::class)) extends ListingMatcher
        {
            /** @return list<string> */
            public function words(): array
            {
                return $this->dictionaryWords();
            }
        };

        expect(implode(' ', $matcher->words()))->toContain('экскаватор')->not->toContain('автобус');
    });
});

describe('админка', function () {
    beforeEach(fn () => $this->actingAs(User::factory()->create()));

    test('страница справочника не показывает новые категории', function () {
        $approved = categoryNamed('Автокран');
        $new = Category::factory()->unapproved()->create(['name' => 'Автобус']);

        Livewire::test(ListCategories::class)
            ->assertCanSeeTableRecords([$approved])
            ->assertCanNotSeeTableRecords([$new]);
    });

    test('оператор заводит категорию с именем новой — она утверждается, а не дублируется', function () {
        $new = Category::factory()->unapproved()->create(['name' => 'Автобус']);

        Livewire::test(ListCategories::class)
            ->callAction('create', ['name' => 'автобус'])
            ->assertHasNoActionErrors();

        expect(Category::sole()->is($new))->toBeTrue()
            ->and($new->refresh()->isApproved())->toBeTrue()
            ->and($new->name)->toBe('Автобус');
    });

    test('подсказка похожих названий не называет новые категории', function () {
        categoryNamed('Автобус пассажирский');
        Category::factory()->unapproved()->create(['name' => 'Автобус']);

        Livewire::test(ListCategories::class)
            ->mountAction('create')
            ->fillForm(['name' => 'Автобус'])
            ->assertSchemaComponentExists('name', checkComponentUsing: fn (Component $field): bool => helperTextOf($field)
                === 'Уже есть похожие: Автобус пассажирский. Проверьте, не заводите ли дубль.');
    });

    test('форма объявления показывает новую технику отдельно, а в выборе категории — только утверждённые', function () {
        categoryNamed('Автокран');
        $listing = pendingRentalWithNewCategory();
        $foreign = Category::factory()->unapproved()->create(['name' => 'Водовоз']);

        Livewire::test(EditListing::class, ['record' => $listing->getRouteKey()])
            ->assertSee('Новая техника: «Автобус»')
            ->assertDontSee('Водовоз')
            // Выбор категории стартует пустым («оставить новую технику»),
            // и среди его вариантов нет ни своей, ни чужой новой записи.
            ->assertFormSet(['category_id' => null])
            ->assertSchemaComponentExists('category_id', checkComponentUsing: fn (Select $field): bool => array_values($field->getOptions()) === ['Автокран'])
            ->assertActionVisible(TestAction::make('renameNewEquipmentCategory')->schemaComponent('new_category'));

        expect($foreign->refresh()->isApproved())->toBeFalse();
    });

    test('сохранение с пустым выбором категории оставляет новую технику, чужую новую выбрать нельзя', function () {
        $listing = pendingRentalWithNewCategory();
        $foreign = Category::factory()->unapproved()->create(['name' => 'Водовоз']);

        Livewire::test(EditListing::class, ['record' => $listing->getRouteKey()])
            ->assertSee('Готовность к публикации')
            ->assertDontSee('Не хватает для публикации: категория')
            ->call('save')
            ->assertHasNoFormErrors();

        expect($listing->refresh()->category->name)->toBe('Автобус');

        Livewire::test(EditListing::class, ['record' => $listing->getRouteKey()])
            ->fillForm(['category_id' => $foreign->id])
            ->call('save')
            ->assertHasFormErrors(['category_id']);

        expect($listing->refresh()->category->name)->toBe('Автобус');
    });

    test('форма нового объявления не предлагает новые категории', function () {
        categoryNamed('Автокран');
        Category::factory()->unapproved()->create(['name' => 'Водовоз']);

        Livewire::test(CreateListing::class)
            ->assertSee('Автокран')
            ->assertDontSee('Водовоз');
    });

    test('оператор переименовывает новую технику на модерации', function () {
        $listing = pendingRentalWithNewCategory('Автобусы');

        Livewire::test(EditListing::class, ['record' => $listing->getRouteKey()])
            ->callAction(TestAction::make('renameNewEquipmentCategory')->schemaComponent('new_category'), [
                'names' => [$listing->category_id => 'автобус'],
            ])
            ->assertHasNoActionErrors();

        expect($listing->category->refresh()->name)->toBe('Автобус')
            ->and($listing->category->isApproved())->toBeFalse();
    });

    test('переименовать новую технику в уже существующую категорию нельзя', function () {
        categoryNamed('Автобус');
        $listing = pendingRentalWithNewCategory('Автобусы');

        Livewire::test(EditListing::class, ['record' => $listing->getRouteKey()])
            ->callAction(TestAction::make('renameNewEquipmentCategory')->schemaComponent('new_category'), [
                'names' => [$listing->category_id => 'автобус'],
            ])
            ->assertHasActionErrors();

        expect($listing->category->refresh()->name)->toBe('Автобусы');
    });

    test('оператор заменяет новую технику существующей категорией — новая уходит из справочника', function () {
        $listing = pendingRentalWithNewCategory();
        $crane = categoryNamed('Автокран');

        Livewire::test(EditListing::class, ['record' => $listing->getRouteKey()])
            ->fillForm(['category_id' => $crane->id])
            ->call('save')
            ->assertHasNoFormErrors();

        expect($listing->refresh()->category_id)->toBe($crane->id)
            ->and(Category::query()->where('name', 'Автобус')->exists())->toBeFalse();
    });

    test('новая техника водителя — отдельным полем, выбор техники предлагает только утверждённую', function () {
        $new = Category::factory()->unapproved()->create(['name' => 'Автобус']);
        Category::factory()->unapproved()->create(['name' => 'Водовоз']);
        $listing = pendingDriverWithNewMachinery($new);
        $excavator = categoryNamed('Экскаватор');

        Livewire::test(EditListing::class, ['record' => $listing->getRouteKey()])
            ->assertSee('Новая техника: «Автобус»')
            ->assertDontSee('Водовоз')
            ->assertFormSet(['machine_categories' => [(string) $excavator->id], 'new_machine_categories' => [(string) $new->id]])
            ->assertSchemaComponentExists('machine_categories', checkComponentUsing: fn (Select $field): bool => array_values($field->getOptions()) === ['Экскаватор'])
            ->assertActionVisible(TestAction::make('renameNewEquipmentMachineCategories')->schemaComponent('new_machine_categories'))
            ->call('save')
            ->assertHasNoFormErrors();

        expect($listing->refresh()->machineCategories->pluck('name')->sort()->values()->all())->toBe(['Автобус', 'Экскаватор']);
    });

    test('оператор заменяет новую технику водителя — новая уходит из справочника', function () {
        $new = Category::factory()->unapproved()->create(['name' => 'Автобус']);
        $listing = pendingDriverWithNewMachinery($new);
        $crane = categoryNamed('Автокран');

        Livewire::test(EditListing::class, ['record' => $listing->getRouteKey()])
            ->fillForm(['machine_categories' => [categoryNamed('Экскаватор')->id, $crane->id], 'new_machine_categories' => []])
            ->call('save')
            ->assertHasNoFormErrors();

        expect($listing->refresh()->machineCategories->pluck('name')->sort()->values()->all())->toBe(['Автокран', 'Экскаватор'])
            ->and(Category::find($new->id))->toBeNull();
    });

    test('чужую новую технику водителю не поставить ни выбором, ни подменой поля новой техники', function () {
        $listing = pendingDriverWithNewMachinery(Category::factory()->unapproved()->create(['name' => 'Автобус']));
        $foreign = Category::factory()->unapproved()->create(['name' => 'Водовоз']);

        Livewire::test(EditListing::class, ['record' => $listing->getRouteKey()])
            ->fillForm(['machine_categories' => [$foreign->id]])
            ->call('save')
            ->assertHasFormErrors(['machine_categories.0']);

        Livewire::test(EditListing::class, ['record' => $listing->getRouteKey()])
            ->set('data.new_machine_categories', [(string) $foreign->id])
            ->call('save');

        expect($listing->refresh()->machineCategories->pluck('name')->all())->not->toContain('Водовоз');
    });

    test('очередь модерации показывает новую технику, а фильтр категорий её не предлагает', function () {
        $listing = pendingRentalWithNewCategory();

        categoryNamed('Автокран');

        $filterOptions = Livewire::test(ListListings::class)
            ->assertTableColumnStateSet('new_equipment', ['Автобус'], $listing)
            ->instance()->getTableFiltersForm()->getComponent('category_id.value', withHidden: true)->getOptions();

        expect($filterOptions)->toContain('Автокран')->not->toContain('Автобус');
    });
});
