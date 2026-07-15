<?php

namespace App\Filament\Resources\Brands;

use App\Filament\Clusters\Catalogs\CatalogsCluster;
use App\Filament\Resources\Brands\Pages\ListBrands;
use App\Filament\Resources\Brands\Schemas\BrandForm;
use App\Filament\Resources\Brands\Tables\BrandsTable;
use App\Models\Brand;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * The operator-managed dictionary of equipment brands (manufacturers).
 * The AI assistant and the web forms pick strictly from this list, so the
 * dictionary fully controls which brands exist in the system.
 */
class BrandResource extends Resource
{
    protected static ?string $model = Brand::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingOffice;

    protected static ?string $cluster = CatalogsCluster::class;

    protected static ?string $modelLabel = 'марка';

    protected static ?string $pluralModelLabel = 'марки';

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?int $navigationSort = 2;

    public static function form(Schema $schema): Schema
    {
        return BrandForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return BrandsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListBrands::route('/'),
        ];
    }
}
