<?php

use App\Filament\Resources\Locations\Pages\ListLocations;
use App\Models\Listing;
use App\Models\Location;
use App\Models\User;
use App\Services\Locations\LocationResolver;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

test('оператор добавляет недостающее место внутрь существующего узла', function () {
    $city = locationNamed('г.Шымкент');

    Livewire::test(ListLocations::class)
        ->callAction('create', ['name' => 'мкр Нурсат', 'parent_id' => $city->id])
        ->assertHasNoActionErrors();

    $added = Location::query()->where('name', 'мкр Нурсат')->sole();
    expect($added->parent_id)->toBe($city->id)
        ->and($added->depth)->toBe(1)
        ->and($added->path)->toBe($city->path.$added->id.'/')
        ->and($added->label())->toBe('мкр Нурсат, г.Шымкент');
});

test('дубль названия у одного родителя не проходит, у разных — допустим', function () {
    $abai = locationNamed('область Абай');
    locationNamed('Абайский район', $abai);

    Livewire::test(ListLocations::class)
        ->callAction('create', ['name' => 'Абайский район', 'parent_id' => $abai->id])
        ->assertHasActionErrors(['name']);

    Livewire::test(ListLocations::class)
        ->callAction('create', ['name' => 'Абайский район', 'parent_id' => locationNamed('г.Шымкент')->id])
        ->assertHasNoActionErrors();

    expect(Location::query()->where('name', 'Абайский район')->count())->toBe(2);
});

test('переименование обновляет поисковый ключ — резолвер находит по новому имени', function () {
    $location = locationNamed('с.Старое');

    Livewire::test(ListLocations::class)
        ->callAction(TestAction::make('edit')->table($location), ['name' => 'с.Новое'])
        ->assertHasNoActionErrors();

    $resolver = app(LocationResolver::class);
    expect($resolver->resolve('Новое')->sole()->id)->toBe($location->id)
        ->and($resolver->resolve('Старое'))->toHaveCount(0);
});

test('удаление узла уносит вложенные места', function () {
    $region = locationNamed('область Абай');
    $district = locationNamed('Абайский район', $region);
    locationNamed('с.Карааул', $district);

    Livewire::test(ListLocations::class)
        ->callAction(TestAction::make('delete')->table($region))
        ->assertNotified('Локация удалена');

    expect(Location::count())->toBe(0);
});

test('узел не удаляется, пока в его поддереве есть объявления', function () {
    $region = locationNamed('область Абай');
    $district = locationNamed('Абайский район', $region);
    Listing::factory()->create(['location_id' => $district->id]);

    Livewire::test(ListLocations::class)
        ->callAction(TestAction::make('delete')->table($region))
        ->assertNotified('Локацию нельзя удалить');

    expect(Location::query()->count())->toBe(2);
});

test('bulk-удаление пропускает используемые узлы и удаляет остальные', function () {
    $used = locationNamed('г.Шымкент');
    Listing::factory()->create(['location_id' => $used->id]);
    $unusedRegion = locationNamed('область Абай');
    locationNamed('Абайский район', $unusedRegion);

    Livewire::test(ListLocations::class)
        ->selectTableRecords([$used->id, $unusedRegion->id])
        ->callAction(TestAction::make('delete')->table()->bulk())
        ->assertNotified('Удалено локаций: 1');

    expect(Location::pluck('name')->all())->toBe(['г.Шымкент']);
});
