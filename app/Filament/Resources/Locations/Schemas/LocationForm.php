<?php

namespace App\Filament\Resources\Locations\Schemas;

use App\Models\Location;
use App\Services\Dictionaries\SimilarNameLookup;
use App\Services\Locations\LocationResolver;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rules\Unique;

class LocationForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                self::nameField(),
                self::parentField()
                    // Re-parenting would invalidate the materialized paths
                    // of the whole subtree — a node is created in place.
                    ->disabledOn('edit')
                    ->helperText(fn (?Location $record): ?string => $record !== null
                        ? 'Перемещение узлов не поддерживается — создайте новый и удалите старый.'
                        : 'Пусто — узел верхнего уровня (область или город республиканского значения).'),
            ]);
    }

    /**
     * Shared with the «новое место» modal of the listing form. The name is
     * unique within its parent, not globally: namesake places all over the
     * country are the normal case in KATO.
     *
     * `$ignoreRecord` must stay false wherever this field lives inside
     * another resource's form: there the surrounding record is a listing,
     * and Filament would exclude it by `listings.id` from a query over the
     * locations table.
     */
    public static function nameField(bool $ignoreRecord = true): TextInput
    {
        return TextInput::make('name')
            ->label('Название')
            ->placeholder('Как в КАТО: «г.Шымкент», «с.Аксуат», «мкр Нурсат»')
            ->required()
            ->maxLength(255)
            ->unique(
                table: Location::class,
                ignoreRecord: $ignoreRecord,
                modifyRuleUsing: fn (Unique $rule, Get $get): Unique => $rule->where(
                    fn ($query) => $get('parent_id') === null
                        ? $query->whereNull('parent_id')
                        : $query->where('parent_id', $get('parent_id')),
                ),
            )
            // «мкр Нурсат» next to «микрорайон Нурсат» inside the same
            // node is one place entered twice, and listings then scatter
            // between the two. Namesakes under different parents are
            // normal in KATO, so the check stays inside the parent.
            ->live(onBlur: true)
            ->helperText(fn (?Model $record, Get $get): ?string => app(SimilarNameLookup::class)->hint(
                self::siblingsOf($get('parent_id'))->when(
                    $record instanceof Location,
                    fn (Builder $query): Builder => $query->whereKeyNot($record->getKey()),
                ),
                $get('name'),
            ))
            ->validationMessages([
                'required' => 'Укажите название места.',
                'unique' => 'Такое место уже есть у этого родителя.',
            ]);
    }

    /**
     * @return Builder<Location>
     */
    private static function siblingsOf(mixed $parentId): Builder
    {
        return Location::query()->when(
            $parentId === null,
            fn (Builder $query): Builder => $query->whereNull('parent_id'),
            fn (Builder $query): Builder => $query->where('parent_id', $parentId),
        );
    }

    public static function parentField(): Select
    {
        return Select::make('parent_id')
            ->label('Находится в')
            ->searchable()
            // The similar-name hint on the name field is scoped to the
            // parent, so it has to be recomputed when the parent changes.
            ->live()
            ->placeholder('Поиск: область, район, город')
            ->getSearchResultsUsing(fn (string $search): array => app(LocationResolver::class)
                ->suggest($search, 20)
                ->mapWithKeys(fn (Location $location): array => [$location->id => $location->label()])
                ->all())
            ->getOptionLabelUsing(fn (mixed $value): ?string => Location::find($value)?->label());
    }
}
