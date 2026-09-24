<?php

use App\Enums\ListingStatus;
use App\Filament\Resources\Categories\Pages\ListCategories;
use App\Filament\Resources\Listings\Pages\CreateListing;
use App\Filament\Resources\Listings\Pages\EditListing;
use App\Filament\Resources\Listings\Pages\ListListings;
use App\Models\Category;
use App\Models\Contact;
use App\Models\Listing;
use App\Models\User;
use App\Services\Ai\CtaLinkBuilder;
use App\Services\Ai\ListingEmbeddings;
use App\Services\Ai\ListingMatcher;
use Filament\Actions\Testing\TestAction;
use Filament\Schemas\Components\Component;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

    test('веб-форма поставщика показывает его новую категорию, но не чужую', function () {
        categoryNamed('Автокран');
        $own = Listing::factory()->create([
            'status' => ListingStatus::Draft,
            'category_id' => Category::factory()->unapproved()->create(['name' => 'Автобус'])->id,
        ]);
        Category::factory()->unapproved()->create(['name' => 'Водовоз']);

        $this->get(app(CtaLinkBuilder::class)->editUrl($own))
            ->assertOk()
            ->assertSee('Автокран')
            ->assertSee('Автобус')
            ->assertDontSee('Водовоз');
    });

    test('веб-форма не принимает чужую новую категорию и отпускает свою при замене', function () {
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

        $this->post($updateUrl, [...$payload, 'category_id' => $own->category_id])->assertSessionHasNoErrors();
        expect($own->refresh()->status)->toBe(ListingStatus::PendingModeration);

        $own->update(['status' => ListingStatus::Draft]);
        $this->post($updateUrl, [...$payload, 'category_id' => categoryNamed('Автокран')->id])->assertSessionHasNoErrors();

        expect($own->refresh()->category->name)->toBe('Автокран')
            ->and(Category::query()->where('name', 'Автобус')->exists())->toBeFalse();
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

    test('форма объявления помечает новую технику и не предлагает чужую новую', function () {
        categoryNamed('Автокран');
        $listing = pendingRentalWithNewCategory();
        Category::factory()->unapproved()->create(['name' => 'Водовоз']);

        Livewire::test(EditListing::class, ['record' => $listing->getRouteKey()])
            ->assertSee('Автобус (новая)')
            ->assertSee('Новая техника: «Автобус»')
            ->assertSee('Автокран')
            ->assertDontSee('Водовоз')
            ->assertActionVisible(TestAction::make('renameNewEquipmentCategory')->schemaComponent('category_id'));
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
            ->callAction(TestAction::make('renameNewEquipmentCategory')->schemaComponent('category_id'), [
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
            ->callAction(TestAction::make('renameNewEquipmentCategory')->schemaComponent('category_id'), [
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

    test('оператор заменяет новую технику водителя — новая уходит из справочника', function () {
        $new = Category::factory()->unapproved()->create(['name' => 'Автобус']);
        $listing = pendingDriverWithNewMachinery($new);

        Livewire::test(EditListing::class, ['record' => $listing->getRouteKey()])
            ->assertSee('Автобус (новая)')
            ->fillForm(['machine_categories' => [categoryNamed('Экскаватор')->id]])
            ->call('save')
            ->assertHasNoFormErrors();

        expect($listing->refresh()->machineCategories->pluck('name')->all())->toBe(['Экскаватор'])
            ->and(Category::find($new->id))->toBeNull();
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
