<?php

use App\Models\Category;
use Database\Seeders\CategorySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('сидер заводит стандартный справочник техники и идемпотентен', function () {
    $this->seed(CategorySeeder::class);

    expect(Category::count())->toBe(72)
        ->and(Category::where('name', 'Автокраны')->exists())->toBeTrue();

    // Повторный запуск не создаёт дублей и не трогает существующие записи.
    $this->seed(CategorySeeder::class);

    expect(Category::count())->toBe(72);
});

test('сидер не перезаписывает категорию, заведённую оператором', function () {
    // Кроме названия у категории полей не осталось, поэтому «запись та же»
    // проверяется по id и дате создания. Одного id мало: «Автокраны» стоят
    // в списке первыми, и пересозданная запись получила бы тот же номер.
    $manual = Category::factory()->create(['name' => 'Автокраны', 'created_at' => now()->subYear()]);

    $this->seed(CategorySeeder::class);

    $survived = Category::query()->where('name', 'Автокраны')->sole();

    expect(Category::count())->toBe(72)
        ->and($survived->id)->toBe($manual->id)
        ->and($survived->created_at->toDateTimeString())->toBe($manual->created_at->toDateTimeString());
});
