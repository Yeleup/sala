<?php

use App\Enums\ListingType;
use App\Models\Category;
use Database\Seeders\CategorySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('сидер заводит стандартный справочник техники и идемпотентен', function () {
    $this->seed(CategorySeeder::class);

    expect(Category::count())->toBe(72)
        ->and(Category::where('type', ListingType::Service)->count())->toBe(0)
        ->and(Category::where('name', 'Автокраны')->sole()->type)->toBe(ListingType::Equipment);

    // Повторный запуск не создаёт дублей и не трогает существующие записи.
    $this->seed(CategorySeeder::class);

    expect(Category::count())->toBe(72);
});

test('сидер не перезаписывает категорию, заведённую оператором', function () {
    $manual = Category::factory()->service()->create(['name' => 'Автокраны']);

    $this->seed(CategorySeeder::class);

    expect(Category::count())->toBe(72)
        ->and($manual->refresh()->type)->toBe(ListingType::Service);
});
