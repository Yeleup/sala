<?php

namespace App\Filament\Resources\CustomerRequests\Schemas;

use App\Filament\Resources\Listings\ListingResource;
use App\Models\CustomerRequest;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class CustomerRequestInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Заявка')
                    ->schema([
                        TextEntry::make('status')
                            ->label('Статус ответа')
                            ->badge(),
                        TextEntry::make('created_at')
                            ->label('Создана')
                            ->dateTime('d.m.Y H:i'),
                        TextEntry::make('query_text')
                            ->label('Текст запроса')
                            ->columnSpanFull(),
                        TextEntry::make('customer.phone')
                            ->label('Телефон заказчика'),
                        TextEntry::make('customer.display_name')
                            ->label('Имя заказчика')
                            ->state(fn (CustomerRequest $record): ?string => $record->customer?->displayName())
                            ->placeholder('—'),
                    ])
                    ->columns(2),
                Section::make('Выбранное объявление')
                    ->schema([
                        TextEntry::make('listing.title')
                            ->label('Название')
                            ->state(fn (CustomerRequest $record): ?string => $record->listing?->displayName())
                            ->placeholder('—')
                            ->url(fn (CustomerRequest $record): string => ListingResource::getUrl('view', ['record' => $record->listing_id])),
                        TextEntry::make('listing.category.name')
                            ->label('Категория')
                            ->placeholder('—'),
                        TextEntry::make('listing.supplier.phone')
                            ->label('Телефон поставщика'),
                        TextEntry::make('listing.description')
                            ->label('Описание')
                            ->placeholder('—')
                            ->columnSpanFull(),
                    ])
                    ->columns(2),
            ]);
    }
}
