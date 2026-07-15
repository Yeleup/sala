<?php

namespace Database\Seeders;

use App\Models\Brand;
use Illuminate\Database\Seeder;

/**
 * The standard dictionary of equipment brands (manufacturers). Idempotent:
 * existing brands are kept untouched, so re-running on any environment is safe.
 */
class BrandSeeder extends Seeder
{
    /** @var list<string> */
    private const array EQUIPMENT_BRANDS = [
        'Ammann',
        'Bobcat',
        'Bomag',
        'Case Construction',
        'Caterpillar',
        'Develon / Doosan',
        'Dynapac',
        'Epiroc',
        'Hitachi',
        'Hyundai Construction Equipment',
        'JCB',
        'John Deere',
        'Kobelco',
        'Komatsu',
        'Kubota',
        'Liebherr',
        'LiuGong',
        'Manitou',
        'Metso',
        'Sandvik',
        'SANY',
        'SDLG',
        'Shantui',
        'Takeuchi',
        'Volvo Construction Equipment',
        'Wacker Neuson',
        'XCMG',
        'Zoomlion',
        'Амкодор',
        'БЕЛАЗ',
        'Галичский автокрановый завод / ГАКЗ',
        'КАМАЗ',
        'Клинцовский автокрановый завод / КАЗ',
        'МАЗ',
        'ТВЭКС / UMG',
        'Тонар',
        'УРАЛ',
        'ЧЕТРА',
    ];

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        foreach (self::EQUIPMENT_BRANDS as $name) {
            Brand::query()->firstOrCreate(['name' => $name]);
        }
    }
}
