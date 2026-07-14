<?php

namespace App\Filament\Resources\Locations\Schemas;

use App\Models\Location;
use App\Services\Locations\LocationResolver;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Validation\Rules\Unique;

class LocationForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->label('Название')
                    ->placeholder('Как в КАТО: «г.Шымкент», «с.Аксуат», «мкр Нурсат»')
                    ->required()
                    ->maxLength(255)
                    ->unique(
                        ignoreRecord: true,
                        modifyRuleUsing: fn (Unique $rule, Get $get): Unique => $rule->where(
                            fn ($query) => $get('parent_id') === null
                                ? $query->whereNull('parent_id')
                                : $query->where('parent_id', $get('parent_id')),
                        ),
                    )
                    ->validationMessages([
                        'required' => 'Укажите название места.',
                        'unique' => 'Такое место уже есть у этого родителя.',
                    ]),
                Select::make('parent_id')
                    ->label('Находится в')
                    ->searchable()
                    ->placeholder('Поиск: область, район, город')
                    ->getSearchResultsUsing(fn (string $search): array => app(LocationResolver::class)
                        ->suggest($search, 20)
                        ->mapWithKeys(fn (Location $location): array => [$location->id => $location->label()])
                        ->all())
                    ->getOptionLabelUsing(fn (mixed $value): ?string => Location::find($value)?->label())
                    // Re-parenting would invalidate the materialized paths
                    // of the whole subtree — a node is created in place.
                    ->disabledOn('edit')
                    ->helperText(fn (?Location $record): ?string => $record !== null
                        ? 'Перемещение узлов не поддерживается — создайте новый и удалите старый.'
                        : 'Пусто — узел верхнего уровня (область или город республиканского значения).'),
            ]);
    }
}
