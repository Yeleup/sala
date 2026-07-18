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

test('нечёткое сопоставление сводит искажённое название места к справочнику', function () {
    $district = locationNamed('Сарыагашский район', locationNamed('Туркестанская область'));
    locationNamed('г.Сарыагаш', $district);
    locationNamed('с.Карагаш', locationNamed('Карагашский с.о.', $district));

    $resolver = app(LocationResolver::class);

    // «Сарагаш» — искажение транскрибации: близки и город, и село, но
    // название места решается как одноимённые — крупнейшая единица.
    expect($resolver->detectPlace('Сарагаш')?->name)->toBe('г.Сарыагаш')
        // Для сбора поставщика близкие кандидаты — список на выбор.
        ->and($resolver->resolve('Сарагаш')->pluck('name')->all())->toContain('г.Сарыагаш');
});

test('несколько одинаково близких мест: название нераспознано, кандидаты — на выбор', function () {
    $region = locationNamed('Туркестанская область');
    locationNamed('с.Карабулан', $region);
    locationNamed('с.Карабулат', $region);

    $resolver = app(LocationResolver::class);

    expect($resolver->detectPlace('Карабулак'))->toBeNull()
        ->and($resolver->resolve('Карабулак'))->toHaveCount(2);
});

test('свободный текст запроса не подтягивается нечётко — только точное совпадение', function () {
    locationNamed('с.Трактовое', locationNamed('Акмолинская область'));

    $resolver = app(LocationResolver::class);

    // «трактор» в одной букве от «Трактовое», но это слово запроса, а не
    // название места — локация к нему не привязывается.
    expect($resolver->detectInQuery('нужен трактор'))->toBeNull()
        ->and($resolver->detectPlace('Трактовое')?->name)->toBe('с.Трактовое');
});

test('автокомплит локаций отдаёт совпадения по началу названия', function () {
    importSampleKatoTree();

    $this->getJson('/locations/search?q=кара')
        ->assertOk()
        ->assertJsonPath('0.label', 'Каратауский район, г.Шымкент');

    $this->getJson('/locations/search?q=')
        ->assertOk()
        ->assertExactJson([]);
});

test('автокомплит показывает найденный узел вместе с его районами', function () {
    importSampleKatoTree();

    // Город первым, за ним всё, что внутри него.
    $this->getJson('/locations/search?q=шымкент')
        ->assertOk()
        ->assertJsonCount(3)
        ->assertJsonPath('0.label', 'г.Шымкент')
        ->assertJsonPath('1.label', 'Абайский район, г.Шымкент')
        ->assertJsonPath('2.label', 'Каратауский район, г.Шымкент');
});

test('несколько слов в автокомплите сужают ветку', function () {
    importSampleKatoTree();

    // «Абайский район» есть и в области Абай — но ветка задана Шымкентом.
    $this->getJson('/locations/search?q=шымкент абай')
        ->assertOk()
        ->assertJsonCount(1)
        ->assertJsonPath('0.label', 'Абайский район, г.Шымкент');
});
