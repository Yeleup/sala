<?php

namespace App\Filament\Resources\Categories\Schemas;

use App\Enums\ListingType;
use App\Models\Category;
use App\Services\Dictionaries\SimilarNameLookup;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class CategoryForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                self::nameField(),
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

    /**
     * Shared with the «новая категория» modal of the listing form, so a
     * category added on the fly is held to the same uniqueness rule as one
     * added on the dictionary page.
     *
     * `$ignoreRecord` must stay false wherever this field lives inside
     * another resource's form: there the surrounding record is a listing,
     * and Filament would exclude it by `listings.id` from a query over the
     * categories table.
     */
    public static function nameField(bool $ignoreRecord = true): TextInput
    {
        return TextInput::make('name')
            ->label('Название')
            ->placeholder('Например: Автокран')
            ->required()
            ->maxLength(255)
            ->unique(table: Category::class, ignoreRecord: $ignoreRecord)
            // A near-duplicate («Автокран» next to «Кран автомобильный»)
            // splits one kind of offer in two and hides half of it from
            // the customer; uniqueness alone catches only exact repeats.
            ->live(onBlur: true)
            ->helperText(fn (?Model $record, Get $get): ?string => app(SimilarNameLookup::class)->hint(
                Category::query()->when(
                    $record instanceof Category,
                    fn (Builder $query): Builder => $query->whereKeyNot($record->getKey()),
                ),
                $get('name'),
            ))
            ->validationMessages([
                'required' => 'Укажите название категории.',
                'unique' => 'Такая категория уже есть.',
            ]);
    }
}
