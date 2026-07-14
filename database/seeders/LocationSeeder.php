<?php

namespace Database\Seeders;

use App\Services\Locations\KatoTreeImporter;
use Illuminate\Database\Seeder;

/**
 * Fills the location dictionary from the KATO tree shipped with the repo.
 * Idempotent — the importer only adds missing nodes.
 */
class LocationSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(KatoTreeImporter $importer): void
    {
        $importer->import(database_path('data/kazakhstan-kato-tree-2026.txt'));
    }
}
