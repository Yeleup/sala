<?php

namespace App\Filament\Resources\Listings\Tables;

use App\Enums\ListingOrigin;
use App\Enums\ListingStatus;
use App\Enums\ListingType;
use App\Filament\Resources\Listings\ListingResource;
use App\Models\Listing;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\RecordActionsPosition;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ListingsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            // Every column can be switched off, and the four the operator
            // needs least often start off: they do not all fit a laptop
            // screen, and which ones matter depends on what he is doing
            // — sorting out the moderation queue, or calling round the
            // suppliers whose listings expire tomorrow. Nothing is removed,
            // and a column switched off still takes part in the search. The
            // choice is remembered for the session.
            ->columnManager()
            ->persistColumnsInSession()
            ->columns([
                TextColumn::make('id')
                    ->label('№')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('supplier.phone')
                    ->label('Поставщик')
                    ->searchable()
                    ->toggleable(),
                // A supplier who never wrote to the bot cannot be reached
                // for free and will not answer the renewal poll — the
                // operator has to spot such listings before they expire.
                IconColumn::make('supplier_wrote')
                    ->label('Писал боту')
                    ->boolean()
                    ->state(fn (Listing $record): bool => (bool) $record->supplier?->hasEverWritten())
                    ->toggleable(),
                TextColumn::make('type')
                    ->label('Тип')
                    ->badge()
                    ->toggleable(),
                TextColumn::make('title')
                    ->label('Название')
                    ->searchable()
                    ->limit(40)
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('category.name')
                    ->label('Категория')
                    ->searchable()
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('brand.name')
                    ->label('Марка')
                    ->searchable()
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('location.name')
                    ->label('Локация')
                    ->searchable()
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('price')
                    ->label('Цена/Тариф')
                    ->placeholder('—')
                    ->toggleable(),
                // An icon rather than a badge: the word cost as much width as
                // a whole column, while the colour and the shape already say
                // what the state is. The tooltip spells it out and adds how
                // long a published listing is still good for.
                IconColumn::make('status')
                    ->label('Статус')
                    ->tooltip(fn (Listing $record): string => $record->expires_at
                        ? $record->status->getLabel().', актуально до '.$record->expires_at->format('d.m.Y H:i')
                        : $record->status->getLabel())
                    ->toggleable(),
                TextColumn::make('origin')
                    ->label('Источник')
                    ->badge()
                    ->tooltip(fn (Listing $record): ?string => $record->author?->name)
                    ->placeholder('неизвестно')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')
                    ->label('Создано')
                    ->dateTime('d.m.Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
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
            // One menu at the start of the row instead of labelled links at
            // its end. Inline they took some 500px and, since the columns do
            // not fit a laptop screen anyway, they sat behind the
            // horizontal scroll — the operator had to drag the scrollbar to
            // reach the very controls the row exists for. Nothing is dropped,
            // and clicking the row still opens the form. The three sections
            // keep a nine-item menu readable: what can be done to any listing,
            // the lifecycle verdicts, then deletion.
            ->recordActions([
                ActionGroup::make([
                    ActionGroup::make([
                        EditAction::make(),
                        ListingResource::previewAction(),
                    ])->dropdown(false),
                    ActionGroup::make([
                        ListingResource::publishAction(),
                        ListingResource::submitForModerationAction(),
                        ListingResource::approveAction(),
                        ListingResource::rejectAction(),
                        ListingResource::renewAction(),
                        ListingResource::archiveAction(),
                    ])->dropdown(false),
                    ActionGroup::make([
                        DeleteAction::make()
                            ->label('Удалить')
                            ->modalHeading('Удалить объявление?')
                            ->modalDescription('Объявление удаляется безвозвратно вместе с медиа и заявками по нему.'),
                    ])->dropdown(false),
                ]),
            ], position: RecordActionsPosition::BeforeCells)
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
