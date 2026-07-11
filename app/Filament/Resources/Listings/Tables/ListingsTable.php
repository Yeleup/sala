<?php

namespace App\Filament\Resources\Listings\Tables;

use App\Enums\ListingStatus;
use App\Enums\ListingType;
use App\Filament\Resources\Listings\ListingResource;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class ListingsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')
                    ->label('№')
                    ->sortable(),
                TextColumn::make('supplier.phone')
                    ->label('Поставщик')
                    ->searchable(),
                TextColumn::make('type')
                    ->label('Тип')
                    ->badge(),
                TextColumn::make('category')
                    ->label('Категория')
                    ->searchable()
                    ->placeholder('—'),
                TextColumn::make('location')
                    ->label('Локация')
                    ->searchable()
                    ->placeholder('—'),
                TextColumn::make('price')
                    ->label('Цена/Тариф')
                    ->placeholder('—'),
                TextColumn::make('status')
                    ->label('Статус')
                    ->badge(),
                TextColumn::make('expires_at')
                    ->label('Актуально до')
                    ->dateTime('d.m.Y H:i')
                    ->sortable()
                    ->placeholder('—'),
                TextColumn::make('created_at')
                    ->label('Создано')
                    ->dateTime('d.m.Y H:i')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Статус')
                    ->options(ListingStatus::class)
                    ->default(ListingStatus::PendingModeration->value),
                SelectFilter::make('type')
                    ->label('Тип')
                    ->options(ListingType::class),
            ])
            ->recordActions([
                ViewAction::make(),
                ListingResource::approveAction(),
                ListingResource::rejectAction(),
            ])
            ->defaultSort('created_at');
    }
}
