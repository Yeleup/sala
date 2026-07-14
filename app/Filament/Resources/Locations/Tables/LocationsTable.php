<?php

namespace App\Filament\Resources\Locations\Tables;

use App\Models\Listing;
use App\Models\Location;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;

class LocationsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Название')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('parent_chain')
                    ->label('Находится в')
                    ->state(fn (Location $record): ?string => $record->ancestors()
                        ->sortByDesc('depth')
                        ->pluck('name')
                        ->implode(', ') ?: null)
                    ->placeholder('—'),
                TextColumn::make('depth')
                    ->label('Уровень')
                    ->sortable(),
                TextColumn::make('listings_count')
                    ->label('Объявлений')
                    ->counts('listings')
                    ->sortable(),
            ])
            ->recordActions([
                EditAction::make(),
                self::deleteAction(),
            ])
            ->toolbarActions([
                self::bulkDeleteAction(),
            ])
            ->defaultSort('name');
    }

    protected static function deleteAction(): Action
    {
        return Action::make('delete')
            ->label('Удалить')
            ->icon(Heroicon::OutlinedTrash)
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading('Удалить локацию?')
            ->modalDescription(function (Location $record): string {
                $descendants = Location::query()->where('path', 'like', $record->path.'%')->count() - 1;

                return $descendants > 0
                    ? "Вместе с узлом удалятся вложенные места ({$descendants} шт.)."
                    : 'Узел будет удалён из справочника.';
            })
            ->action(function (Location $record): void {
                if (self::subtreeHasListings($record)) {
                    Notification::make()
                        ->title('Локацию нельзя удалить')
                        ->body('На неё или на вложенные места ссылаются объявления — сначала переведите их в другую локацию.')
                        ->danger()
                        ->send();

                    return;
                }

                $record->delete();

                Notification::make()
                    ->title('Локация удалена')
                    ->success()
                    ->send();
            });
    }

    protected static function bulkDeleteAction(): BulkAction
    {
        return BulkAction::make('delete')
            ->label('Удалить выбранные')
            ->icon(Heroicon::OutlinedTrash)
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading('Удалить выбранные локации?')
            ->modalDescription('Вложенные места удаляются вместе с узлами. Локации, на которые (или на вложенные места которых) ссылаются объявления, будут пропущены.')
            ->deselectRecordsAfterCompletion()
            ->action(function (Collection $records): void {
                $deleted = 0;
                $skipped = [];

                foreach ($records as $record) {
                    // A previously deleted ancestor from the same selection
                    // takes its subtree with it.
                    if (! Location::query()->whereKey($record->id)->exists()) {
                        continue;
                    }

                    if (self::subtreeHasListings($record)) {
                        $skipped[] = $record->name;

                        continue;
                    }

                    $record->delete();
                    $deleted++;
                }

                if ($deleted > 0) {
                    Notification::make()
                        ->title("Удалено локаций: {$deleted}")
                        ->success()
                        ->send();
                }

                if ($skipped !== []) {
                    Notification::make()
                        ->title('Пропущены используемые локации')
                        ->body('На них ссылаются объявления: '.implode(', ', $skipped))
                        ->danger()
                        ->send();
                }
            });
    }

    protected static function subtreeHasListings(Location $location): bool
    {
        return Listing::query()
            ->whereHas('location', fn ($query) => $query->where('path', 'like', $location->path.'%'))
            ->exists();
    }
}
