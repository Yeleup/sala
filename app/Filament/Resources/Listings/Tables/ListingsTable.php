<?php

namespace App\Filament\Resources\Listings\Tables;

use App\Enums\ListingStatus;
use App\Enums\ListingType;
use App\Filament\Resources\Listings\ListingResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
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
                TextColumn::make('category.name')
                    ->label('Категория')
                    ->searchable()
                    ->placeholder('—'),
                TextColumn::make('location.name')
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
                SelectFilter::make('category_id')
                    ->label('Категория')
                    ->relationship('category', 'name'),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
                ListingResource::submitForModerationAction(),
                ListingResource::approveAction(),
                ListingResource::rejectAction(),
                DeleteAction::make()
                    ->label('Удалить')
                    ->modalHeading('Удалить объявление?')
                    ->modalDescription('Объявление удаляется безвозвратно вместе с медиа и заявками по нему.'),
            ])
            ->toolbarActions([
                DeleteBulkAction::make()
                    ->label('Удалить выбранные')
                    ->modalHeading('Удалить выбранные объявления?')
                    ->modalDescription('Объявления удаляются безвозвратно вместе с медиа и заявками по ним.'),
            ])
            ->defaultSort('created_at');
    }
}
