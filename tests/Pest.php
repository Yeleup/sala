<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
 // ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

/**
 * Подключённая компания Dereu этой инсталляции; тест должен выставить
 * config('services.dereu.external_id') в 'org_test'.
 */
function connectedDereuCompany(array $attributes = []): \App\Models\DereuCompany
{
    return \App\Models\DereuCompany::factory()->create(array_merge(['external_id' => 'org_test'], $attributes));
}

/**
 * Категория из операторского справочника (создаётся при первом обращении) —
 * для объявлений в тестах: `'category_id' => categoryNamed('Автокран')->id`.
 */
function categoryNamed(string $name, \App\Enums\ListingType $type = \App\Enums\ListingType::Equipment): \App\Models\Category
{
    return \App\Models\Category::query()->firstOrCreate(['name' => $name], ['type' => $type]);
}

/**
 * Узел справочника локаций КАТО (создаётся при первом обращении) — для
 * объявлений в тестах: `'location_id' => locationNamed('г.Шымкент')->id`.
 */
function locationNamed(string $name, ?\App\Models\Location $parent = null): \App\Models\Location
{
    return \App\Models\Location::query()->firstOrCreate([
        'name' => $name,
        'parent_id' => $parent?->id,
    ]);
}
