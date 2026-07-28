<?php

use App\Models\Brand;
use App\Models\Category;
use App\Models\Location;
use App\Services\Dictionaries\SimilarNameLookup;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function lookup(): SimilarNameLookup
{
    return app(SimilarNameLookup::class);
}

test('опечатка находит уже заведённое название', function () {
    categoryNamed('Экскаватор');

    expect(lookup()->similarTo(Category::query(), 'Эксковатор'))->toBe(['Экскаватор']);
});

test('название, входящее в уже заведённое, находится', function () {
    categoryNamed('Автокран');

    // Оператор набирает «Кран», а в справочнике «Автокран» — это тот же
    // вид техники под другим именем.
    expect(lookup()->similarTo(Category::query(), 'Кран'))->toBe(['Автокран']);
});

test('переставленные слова находятся — порядок для триграмм почти не важен', function () {
    categoryNamed('Кран автомобильный');

    expect(lookup()->similarTo(Category::query(), 'Автомобильный кран'))->toBe(['Кран автомобильный']);
});

test('регистр не мешает', function () {
    brandNamed('Hitachi');

    expect(lookup()->similarTo(Brand::query(), 'HITACHI'))->toBe(['Hitachi']);
});

test('сокращение находит полное написание', function () {
    $shymkent = locationNamed('г.Шымкент');
    locationNamed('мкр Нурсат', $shymkent);

    // Общее у них только второе слово — но это одно и то же место.
    expect(lookup()->similarTo(Location::query()->where('parent_id', $shymkent->id), 'микрорайон Нурсат'))
        ->toBe(['мкр Нурсат']);
});

test('непохожее название не показывается', function () {
    categoryNamed('Автокран');
    categoryNamed('Экскаватор');

    expect(lookup()->similarTo(Category::query(), 'Сварщик'))->toBe([]);
});

test('разные виды техники друг о друге не предупреждают', function () {
    categoryNamed('Автокран');

    // Порог подобран так, чтобы эта пара осталась по разные стороны:
    // они начинаются одинаково, но это разная техника.
    expect(lookup()->similarTo(Category::query(), 'Автовышка'))->toBe([]);
});

test('пока набрано меньше трёх букв, подсказки нет', function () {
    categoryNamed('Автокран');

    // Обрывок похож на пол-справочника — предупреждать рано.
    expect(lookup()->similarTo(Category::query(), 'Ав'))->toBe([])
        ->and(lookup()->similarTo(Category::query(), ''))->toBe([])
        ->and(lookup()->similarTo(Category::query(), null))->toBe([]);
});

test('символы шаблона в наборе не ломают поиск', function () {
    categoryNamed('Автокран');

    // «%» не должен превратиться в «найди что угодно».
    expect(lookup()->similarTo(Category::query(), '%%%'))->toBe([]);
});

test('подсказка называет найденные варианты, иначе молчит', function () {
    categoryNamed('Экскаватор');

    expect(lookup()->hint(Category::query(), 'Эксковатор'))
        ->toBe('Уже есть похожие: Экскаватор. Проверьте, не заводите ли дубль.')
        ->and(lookup()->hint(Category::query(), 'Сварщик'))->toBeNull();
});

test('при переименовании запись не показывает саму себя', function () {
    $category = categoryNamed('Экскаватор');

    expect(lookup()->similarTo(Category::query()->whereKeyNot($category->id), 'Экскаватор'))->toBe([]);
});
