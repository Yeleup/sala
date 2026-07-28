<?php

namespace App\Filament\Resources\Listings\Tables;

use App\Enums\ListingOrigin;
use App\Enums\ListingStatus;
use App\Enums\ListingType;
use App\Filament\Resources\Listings\ListingResource;
use App\Models\Listing;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

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
                // A supplier who never wrote to the bot cannot be reached
                // for free and will not answer the renewal poll — the
                // operator has to spot such listings before they expire.
                IconColumn::make('supplier_wrote')
                    ->label('Писал боту')
                    ->boolean()
                    ->state(fn (Listing $record): bool => (bool) $record->supplier?->hasEverWritten()),
                TextColumn::make('type')
                    ->label('Тип')
                    ->badge(),
                TextColumn::make('title')
                    ->label('Название')
                    ->searchable()
                    ->limit(40)
                    ->placeholder('—'),
                TextColumn::make('category.name')
                    ->label('Категория')
                    ->searchable()
                    ->placeholder('—'),
                TextColumn::make('brand.name')
                    ->label('Марка')
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
                TextColumn::make('origin')
                    ->label('Источник')
                    ->badge()
                    ->tooltip(fn (Listing $record): ?string => $record->author?->name)
                    ->placeholder('неизвестно'),
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
                // Not preselected: the working sets live on the page's tabs,
                // and this filter reaches the remaining statuses.
                SelectFilter::make('status')
                    ->label('Статус')
                    ->options(ListingStatus::class),
                SelectFilter::make('type')
                    ->label('Тип')
                    ->options(ListingType::class),
                SelectFilter::make('category_id')
                    ->label('Категория')
                    ->relationship('category', 'name'),
                SelectFilter::make('origin')
                    ->label('Источник')
                    ->options(ListingOrigin::class),
                // The operator's window to call a supplier the renewal
                // poll cannot reach and prolong the listing by hand.
                Filter::make('expiring')
                    ->label('Истекает в сутки')
                    ->query(fn (Builder $query): Builder => $query
                        ->where('status', ListingStatus::Published)
                        ->whereBetween('expires_at', [now(), now()->addDay()])),
            ])
            ->recordActions([
                EditAction::make(),
                ListingResource::previewAction(),
                ListingResource::publishAction(),
                ListingResource::submitForModerationAction(),
                ListingResource::approveAction(),
                ListingResource::rejectAction(),
                ListingResource::renewAction(),
                ListingResource::archiveAction(),
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
            // Newest first: the listing the operator has just typed belongs
            // on the first screen, not at the end of a list that grows daily.
            // Listings created back to back by «создать ещё» share a
            // second-granular timestamp; the record key breaks the tie.
            ->defaultSort('created_at', 'desc');
    }
}
