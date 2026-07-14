<?php

use App\Models\Location;
use App\Services\Locations\KatoTreeImporter;
use App\Services\Locations\LocationResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function importSampleKatoTree(): array
{
    return app(KatoTreeImporter::class)->import(base_path('tests/Fixtures/kato-tree-sample.txt'));
}

test('импортёр строит дерево с путями и глубинами и идемпотентен', function () {
    $result = importSampleKatoTree();

    expect($result)->toBe(['created' => 10, 'total' => 10]);

    $semey = Location::query()->where('name', 'г.Семей')->sole();
    expect($semey->parent->name)->toBe('Семей Г.А.')
        ->and($semey->depth)->toBe(2)
        ->and($semey->path)->toStartWith($semey->parent->path);

    $karaul = Location::query()->where('name', 'с.Карааул')->sole();
    expect($karaul->label())->toBe('с.Карааул, Карааульский с.о., Абайский район, область Абай');

    // Одноимённые районы у разных родителей — отдельные узлы.
    expect(Location::query()->where('name', 'Абайский район')->count())->toBe(2);

    $again = importSampleKatoTree();
    expect($again)->toBe(['created' => 0, 'total' => 10]);
});

test('резолвер понимает падежи, казахские буквы и схлопывает Г.А. до города', function () {
    importSampleKatoTree();
    $resolver = app(LocationResolver::class);

    // «Семей Г.А.» и «г.Семей» дают один ключ — остаётся сам город.
    expect($resolver->resolve('в Семее')->sole()->name)->toBe('г.Семей')
        // Казахские буквы приводятся к русским аналогам.
        ->and($resolver->resolve('Аксуат')->sole()->name)->toBe('район Ақсуат')
        // Одноимённые места — список кандидатов.
        ->and($resolver->resolve('Абайский район'))->toHaveCount(2)
        ->and($resolver->resolve('Нарния'))->toHaveCount(0);
});

test('детект локации в запросе: однозначно, крупнейший уровень, неоднозначно', function () {
    importSampleKatoTree();
    $resolver = app(LocationResolver::class);

    expect($resolver->detectInQuery('кран в Караауле')?->name)->toBe('с.Карааул')
        ->and($resolver->detectInQuery('экскаватор в Шымкенте')?->name)->toBe('г.Шымкент')
        // Два одноимённых района на одной глубине — неоднозначно.
        ->and($resolver->detectInQuery('нужен экскаватор в Абайском районе'))->toBeNull()
        // Запрос без локации.
        ->and($resolver->detectInQuery('кран 25 тонн'))->toBeNull();
});

test('автокомплит локаций отдаёт совпадения по началу названия', function () {
    importSampleKatoTree();

    $this->getJson('/locations/search?q=кара')
        ->assertOk()
        ->assertJsonPath('0.label', 'Каратауский район, г.Шымкент');

    $this->getJson('/locations/search?q=шымк')
        ->assertOk()
        ->assertJsonCount(1)
        ->assertJsonPath('0.label', 'г.Шымкент');

    $this->getJson('/locations/search?q=')
        ->assertOk()
        ->assertExactJson([]);
});
