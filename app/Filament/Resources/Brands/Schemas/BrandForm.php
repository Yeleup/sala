<?php

namespace App\Filament\Resources\Brands\Schemas;

use App\Models\Brand;
use App\Services\Dictionaries\SimilarNameLookup;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class BrandForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([self::nameField()]);
    }

    /**
     * Shared with the «новая марка» modal of the listing form, so a brand
     * added on the fly is held to the same uniqueness rule as one added on
     * the dictionary page.
     *
     * `$ignoreRecord` must stay false wherever this field lives inside
     * another resource's form: there the surrounding record is a listing,
     * and Filament would exclude it by `listings.id` from a query over the
     * brands table.
     */
    public static function nameField(bool $ignoreRecord = true): TextInput
    {
        return TextInput::make('name')
            ->label('Название')
            ->placeholder('Например: Hitachi')
            ->required()
            ->maxLength(255)
            ->unique(table: Brand::class, ignoreRecord: $ignoreRecord)
            // «Хитачи» next to «Hitachi» splits one manufacturer in two,
            // and customer search corrects typos against this dictionary.
            ->live(onBlur: true)
            ->helperText(fn (?Model $record, Get $get): ?string => app(SimilarNameLookup::class)->hint(
                Brand::query()->when(
                    $record instanceof Brand,
                    fn (Builder $query): Builder => $query->whereKeyNot($record->getKey()),
                ),
                $get('name'),
            ))
            ->validationMessages([
                'required' => 'Укажите название марки.',
                'unique' => 'Такая марка уже есть.',
            ]);
    }
}
