<?php

namespace App\Filament\Resources\Categories;

use App\Filament\Clusters\Catalogs\CatalogsCluster;
use App\Filament\Resources\Categories\Pages\ListCategories;
use App\Filament\Resources\Categories\Schemas\CategoryForm;
use App\Filament\Resources\Categories\Tables\CategoriesTable;
use App\Models\Category;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * The operator-managed dictionary of listing categories. The AI assistant
 * and the supplier web form pick strictly from this list, so the dictionary
 * fully controls which categories exist in the system.
 */
class CategoryResource extends Resource
{
    protected static ?string $model = Category::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTag;

    protected static ?string $cluster = CatalogsCluster::class;

    protected static ?string $modelLabel = 'категория';

    protected static ?string $pluralModelLabel = 'категории';

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?int $navigationSort = 1;

    public static function form(Schema $schema): Schema
    {
        return CategoryForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return CategoriesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCategories::route('/'),
        ];
    }
}
