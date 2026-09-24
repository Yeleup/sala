<?php

namespace App\Filament\Resources\Categories\Schemas;

use App\Models\Category;
use App\Services\Dictionaries\SimilarNameLookup;
use Closure;
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
            // Unique regardless of letter case, like the database index.
            // When creating, a new category the AI added under this name
            // does not count: it is invisible here, and creating adopts it
            // instead (Category::createByOperator()). A rename must not
            // collide with any other entry.
            ->rule(fn (?Model $record): Closure => function (string $attribute, mixed $value, Closure $fail) use ($ignoreRecord, $record): void {
                $existing = Category::findByName((string) $value);
                $renaming = $ignoreRecord && $record instanceof Category;

                if ($existing === null || ($renaming && $existing->is($record))) {
                    return;
                }

                if ($renaming || $existing->isApproved()) {
                    $fail('Такая категория уже есть.');
                }
            })
            // A near-duplicate («Автокран» next to «Кран автомобильный»)
            // splits one kind of offer in two and hides half of it from
            // the customer; uniqueness alone catches only exact repeats.
            ->live(onBlur: true)
            ->helperText(fn (?Model $record, Get $get): ?string => app(SimilarNameLookup::class)->hint(
                Category::query()->approved()->when(
                    $record instanceof Category,
                    fn (Builder $query): Builder => $query->whereKeyNot($record->getKey()),
                ),
                $get('name'),
            ))
            ->validationMessages([
                'required' => 'Укажите название категории.',
            ]);
    }
}
