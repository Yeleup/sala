<?php

namespace App\Filament\Resources\Locations;

use App\Filament\Clusters\Catalogs\CatalogsCluster;
use App\Filament\Resources\Locations\Pages\ListLocations;
use App\Filament\Resources\Locations\Schemas\LocationForm;
use App\Filament\Resources\Locations\Tables\LocationsTable;
use App\Models\Location;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * The KATO location dictionary. Its baseline comes from the state
 * classifier via `locations:import`; the operator's CRUD covers what the
 * classifier misses — adding a place, renaming, deleting unused nodes.
 * Moving a node to another parent is not supported: delete and recreate.
 */
class LocationResource extends Resource
{
    protected static ?string $model = Location::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMapPin;

    protected static ?string $cluster = CatalogsCluster::class;

    protected static ?string $modelLabel = 'локация';

    protected static ?string $pluralModelLabel = 'локации';

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?int $navigationSort = 3;

    public static function form(Schema $schema): Schema
    {
        return LocationForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return LocationsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListLocations::route('/'),
        ];
    }
}
