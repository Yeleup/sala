<?php

namespace App\Filament\Resources\Categories\Schemas;

use App\Enums\ListingType;
use App\Models\Category;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class CategoryForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->label('Название')
                    ->placeholder('Например: Автокран')
                    ->required()
                    ->maxLength(255)
                    ->unique(ignoreRecord: true)
                    ->validationMessages([
                        'required' => 'Укажите название категории.',
                        'unique' => 'Такая категория уже есть.',
                    ]),
                Select::make('type')
                    ->label('Тип')
                    ->options(ListingType::class)
                    ->required()
                    // Changing the type would break listings that already
                    // carry this category — same protection as deletion.
                    ->disabled(fn (?Category $record): bool => $record?->listings()->exists() ?? false)
                    ->helperText(fn (?Category $record): ?string => ($record?->listings()->exists() ?? false)
                        ? 'Тип нельзя менять: категория используется в объявлениях.'
                        : null)
                    ->validationMessages(['required' => 'Выберите тип категории.']),
            ]);
    }
}
