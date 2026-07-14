<?php

namespace App\Filament\Resources\Listings\Schemas;

use App\Enums\ListingType;
use App\Models\Contact;
use App\Models\Location;
use App\Services\Locations\LocationResolver;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;

/**
 * Operator form for a listing's business fields. The status is not part of
 * the form: lifecycle transitions go through the dedicated actions
 * (submit for moderation, approve, reject) so the domain invariants hold.
 */
class ListingForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('contact_id')
                    ->label('Поставщик')
                    ->relationship('supplier', 'phone')
                    ->getOptionLabelFromRecordUsing(fn (Contact $record): string => filled($record->profile_name)
                        ? "{$record->phone} ({$record->profile_name})"
                        : $record->phone)
                    ->searchable(['phone', 'profile_name'])
                    ->preload()
                    ->required()
                    ->validationMessages(['required' => 'Выберите поставщика.']),
                Select::make('type')
                    ->label('Тип')
                    ->options(ListingType::class)
                    ->required()
                    ->live()
                    // Switching the type invalidates the picked category —
                    // the dictionary types each category.
                    ->afterStateUpdated(fn (Set $set) => $set('category_id', null))
                    ->validationMessages(['required' => 'Выберите тип объявления.']),
                Select::make('category_id')
                    ->label('Категория')
                    ->relationship(
                        'category',
                        'name',
                        modifyQueryUsing: fn (Builder $query, Get $get): Builder => $query
                            ->when($get('type'), fn (Builder $query, mixed $type) => $query->where('type', $type)),
                    )
                    ->searchable()
                    ->preload()
                    ->placeholder('Без категории')
                    ->helperText('Список зависит от выбранного типа.'),
                Textarea::make('description')
                    ->label('Описание')
                    ->rows(4)
                    ->maxLength(2000)
                    ->columnSpanFull(),
                Select::make('location_id')
                    ->label('Локация')
                    ->searchable()
                    ->placeholder('Поиск: город, район или село')
                    ->getSearchResultsUsing(fn (string $search): array => app(LocationResolver::class)
                        ->suggest($search, 20)
                        ->mapWithKeys(fn (Location $location): array => [$location->id => $location->label()])
                        ->all())
                    ->getOptionLabelUsing(fn (mixed $value): ?string => Location::find($value)?->label()),
                TextInput::make('location_detail')
                    ->label('Уточнение адреса')
                    ->placeholder('Например: центр, мкр Нурсат')
                    ->maxLength(255),
                TextInput::make('price')
                    ->label('Цена/Тариф')
                    ->placeholder('Например: 10000 тг/ч')
                    ->maxLength(255),
            ]);
    }
}
