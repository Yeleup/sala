<?php

use App\Filament\Resources\Brands\Pages\ListBrands;
use App\Models\Brand;
use App\Models\Listing;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

test('таблица показывает справочник с числом объявлений у каждой марки', function () {
    $hitachi = Brand::factory()->create(['name' => 'Hitachi']);
    $cat = Brand::factory()->create(['name' => 'CAT']);
    Listing::factory()->count(2)->create(['brand_id' => $hitachi->id]);

    Livewire::test(ListBrands::class)
        ->assertCanSeeTableRecords([$hitachi, $cat])
        ->assertSee('Hitachi')
        ->assertSee('CAT');
});

test('оператор создаёт марку', function () {
    Livewire::test(ListBrands::class)
        ->callAction('create', ['name' => 'Komatsu'])
        ->assertHasNoActionErrors();

    expect(Brand::sole())->name->toBe('Komatsu');
});

test('дубль названия не проходит', function () {
    Brand::factory()->create(['name' => 'Hitachi']);

    Livewire::test(ListBrands::class)
        ->callAction('create', ['name' => 'Hitachi'])
        ->assertHasActionErrors(['name']);

    expect(Brand::count())->toBe(1);
});

test('оператор переименовывает марку', function () {
    $brand = Brand::factory()->create(['name' => 'Хитачи']);

    Livewire::test(ListBrands::class)
        ->callAction(TestAction::make('edit')->table($brand), ['name' => 'Hitachi'])
        ->assertHasNoActionErrors();

    expect($brand->refresh())->name->toBe('Hitachi');
});

test('марка без объявлений удаляется', function () {
    $brand = Brand::factory()->create();

    Livewire::test(ListBrands::class)
        ->callAction(TestAction::make('delete')->table($brand))
        ->assertNotified('Марка удалена');

    expect(Brand::count())->toBe(0);
});

test('марку с объявлениями удалить нельзя', function () {
    $brand = Brand::factory()->create();
    Listing::factory()->create(['brand_id' => $brand->id]);

    Livewire::test(ListBrands::class)
        ->callAction(TestAction::make('delete')->table($brand))
        ->assertNotified('Марку нельзя удалить');

    expect(Brand::count())->toBe(1);
});
