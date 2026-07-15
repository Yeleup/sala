<?php

namespace App\Filament\Resources\Listings\Schemas;

use App\Enums\ListingMediaType;
use App\Enums\ListingType;
use App\Models\Contact;
use App\Models\Listing;
use App\Models\ListingMedia;
use App\Models\Location;
use App\Services\Locations\LocationResolver;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Repeater;
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
                    ->getOptionLabelFromRecordUsing(fn (Contact $record): string => filled($record->displayName())
                        ? "{$record->phone} ({$record->displayName()})"
                        : $record->phone)
                    ->searchable(['phone', 'profile_name', 'display_name'])
                    ->preload()
                    ->required()
                    ->validationMessages(['required' => 'Выберите поставщика.']),
                Select::make('type')
                    ->label('Тип')
                    ->options(ListingType::class)
                    ->required()
                    ->live()
                    // Switching the type invalidates the picked category —
                    // the dictionary types each category. The brand must be
                    // cleared here too: hiding its field alone would keep a
                    // stale brand on a listing switched to «услуга».
                    ->afterStateUpdated(function (Set $set): void {
                        $set('category_id', null);
                        $set('brand_id', null);
                    })
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
                Select::make('brand_id')
                    ->label('Марка')
                    ->relationship('brand', 'name')
                    ->searchable()
                    ->preload()
                    ->placeholder('Без марки')
                    ->helperText('Марка есть только у техники.')
                    ->visible(fn (Get $get): bool => self::isEquipment($get('type'))),
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
                Repeater::make('photos')
                    ->label('Фотографии')
                    ->relationship()
                    ->schema([
                        FileUpload::make('path')
                            ->hiddenLabel()
                            ->disk('public')
                            ->directory('listing-photos')
                            ->image()
                            ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
                            ->maxSize(ListingMedia::MAX_PHOTO_KILOBYTES)
                            ->required()
                            ->validationMessages(['required' => 'Загрузите фото или удалите пустую строку.']),
                    ])
                    // The photos() relation filters by type but does not set
                    // it on create — new rows must be stamped explicitly.
                    ->mutateRelationshipDataBeforeCreateUsing(function (array $data): array {
                        $data['type'] = ListingMediaType::Photo->value;

                        return $data;
                    })
                    ->maxItems(Listing::MAX_PHOTOS)
                    ->defaultItems(0)
                    ->addActionLabel('Добавить фото')
                    ->reorderable(false)
                    ->columnSpanFull(),
            ]);
    }

    /**
     * A live Select holds the raw option value while a hydrated record
     * holds the cast enum — the visibility check must accept both.
     */
    private static function isEquipment(mixed $type): bool
    {
        return ($type instanceof ListingType ? $type : ListingType::tryFrom((string) $type)) === ListingType::Equipment;
    }
}
