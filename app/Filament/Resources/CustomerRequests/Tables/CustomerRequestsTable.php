<?php

namespace App\Filament\Resources\CustomerRequests\Tables;

use App\Enums\CustomerRequestStatus;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class CustomerRequestsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')
                    ->label('№')
                    ->sortable(),
                TextColumn::make('customer.phone')
                    ->label('Заказчик')
                    ->searchable(),
                TextColumn::make('query_text')
                    ->label('Запрос')
                    ->limit(40)
                    ->searchable(),
                TextColumn::make('listing.title')
                    ->label('Объявление')
                    ->state(fn ($record): ?string => $record->listing?->displayName())
                    ->limit(40)
                    ->placeholder('—'),
                TextColumn::make('listing.supplier.phone')
                    ->label('Поставщик'),
                TextColumn::make('status')
                    ->label('Статус ответа')
                    ->badge(),
                TextColumn::make('created_at')
                    ->label('Создана')
                    ->dateTime('d.m.Y H:i')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Статус ответа')
                    ->options(CustomerRequestStatus::class),
            ])
            ->recordActions([
                ViewAction::make(),
            ])
            ->defaultSort('created_at', 'desc');
    }
}
