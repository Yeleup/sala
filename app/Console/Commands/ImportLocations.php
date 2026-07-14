<?php

namespace App\Console\Commands;

use App\Services\Locations\KatoTreeImporter;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('locations:import {path? : Путь к текстовому дереву КАТО}')]
#[Description('Импортирует справочник локаций из текстового дерева КАТО (идемпотентно)')]
class ImportLocations extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(KatoTreeImporter $importer): int
    {
        $path = $this->argument('path') ?? database_path('data/kazakhstan-kato-tree-2026.txt');

        $result = $importer->import($path);

        $this->info("Добавлено локаций: {$result['created']}, всего в справочнике: {$result['total']}.");

        return self::SUCCESS;
    }
}
