<?php

use App\Enums\ListingType;
use App\Filament\Resources\Categories\Pages\ListCategories;
use App\Models\Category;
use App\Models\Listing;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

test('таблица показывает справочник с числом объявлений в каждой категории', function () {
    $crane = Category::factory()->create(['name' => 'Автокран']);
    $digger = Category::factory()->create(['name' => 'Экскаватор']);
    Listing::factory()->count(2)->create(['category_id' => $crane->id]);

    Livewire::test(ListCategories::class)
        ->assertCanSeeTableRecords([$crane, $digger])
        ->assertSee('Автокран')
        ->assertSee('Экскаватор');
});

test('оператор создаёт категорию с типом', function () {
    Livewire::test(ListCategories::class)
        ->callAction('create', ['name' => 'Стропальщик', 'type' => ListingType::Service->value])
        ->assertHasNoActionErrors();

    expect(Category::sole())
        ->name->toBe('Стропальщик')
        ->type->toBe(ListingType::Service);
});

test('без типа категория не создаётся', function () {
    Livewire::test(ListCategories::class)
        ->callAction('create', ['name' => 'Бетононасос', 'type' => null])
        ->assertHasActionErrors(['type']);

    expect(Category::count())->toBe(0);
});

test('дубль названия не проходит', function () {
    Category::factory()->create(['name' => 'Автокран']);

    Livewire::test(ListCategories::class)
        ->callAction('create', ['name' => 'Автокран', 'type' => ListingType::Equipment->value])
        ->assertHasActionErrors(['name']);

    expect(Category::count())->toBe(1);
});

test('оператор переименовывает категорию', function () {
    $category = Category::factory()->create(['name' => 'Кран']);

    Livewire::test(ListCategories::class)
        ->callAction(TestAction::make('edit')->table($category), ['name' => 'Автокран'])
        ->assertHasNoActionErrors();

    expect($category->refresh())->name->toBe('Автокран');
});

test('тип используемой категории не меняется', function () {
    $category = Category::factory()->create(['name' => 'Кран']);
    Listing::factory()->create(['category_id' => $category->id]);

    Livewire::test(ListCategories::class)
        ->callAction(TestAction::make('edit')->table($category), [
            'name' => 'Автокран',
            'type' => ListingType::Service->value,
        ]);

    // Название обновилось, а тип защищён: поле заблокировано в форме.
    expect($category->refresh())
        ->name->toBe('Автокран')
        ->type->toBe(ListingType::Equipment);
});

test('категория без объявлений удаляется', function () {
    $category = Category::factory()->create();

    Livewire::test(ListCategories::class)
        ->callAction(TestAction::make('delete')->table($category))
        ->assertNotified('Категория удалена');

    expect(Category::count())->toBe(0);
});

test('категорию с объявлениями удалить нельзя', function () {
    $category = Category::factory()->create();
    Listing::factory()->create(['category_id' => $category->id]);

    Livewire::test(ListCategories::class)
        ->callAction(TestAction::make('delete')->table($category))
        ->assertNotified('Категорию нельзя удалить');

    expect(Category::count())->toBe(1);
});
