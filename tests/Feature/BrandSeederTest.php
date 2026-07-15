<?php

use App\Models\Brand;
use Database\Seeders\BrandSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('сидер заводит стандартный справочник марок и идемпотентен', function () {
    $this->seed(BrandSeeder::class);

    expect(Brand::count())->toBe(38)
        ->and(Brand::where('name', 'Hitachi')->exists())->toBeTrue()
        ->and(Brand::where('name', 'КАМАЗ')->exists())->toBeTrue();

    // Повторный запуск не создаёт дублей и не трогает существующие записи.
    $this->seed(BrandSeeder::class);

    expect(Brand::count())->toBe(38);
});

test('сидер не дублирует марку, заведённую оператором', function () {
    $manual = Brand::factory()->create(['name' => 'Caterpillar']);

    $this->seed(BrandSeeder::class);

    expect(Brand::count())->toBe(38)
        ->and(Brand::where('name', 'Caterpillar')->sole()->id)->toBe($manual->id);
});
