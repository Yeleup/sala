<?php

namespace App\Filament\Resources\Categories\Tables;

use App\Models\Category;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class CategoriesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            // New categories the AI added wait for the approval of their
            // listing on the moderation form, not here.
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->approved())
            ->columns([
                TextColumn::make('name')
                    ->label('Название')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('listings_count')
                    ->label('Объявлений')
                    ->counts('listings')
                    ->sortable(),
                TextColumn::make('created_at')
                    ->label('Создана')
                    ->dateTime('d.m.Y H:i')
                    ->sortable(),
            ])
            ->recordActions([
                EditAction::make(),
                Action::make('delete')
                    ->label('Удалить')
                    ->icon(Heroicon::OutlinedTrash)
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading('Удалить категорию?')
                    ->modalDescription('AI и веб-форма перестанут предлагать её поставщикам.')
                    ->action(function (Category $record): void {
                        if ($record->listings()->exists()) {
                            Notification::make()
                                ->title('Категорию нельзя удалить')
                                ->body('Она используется в объявлениях — сначала переведите их в другую категорию.')
                                ->danger()
                                ->send();

                            return;
                        }

                        $record->delete();

                        Notification::make()
                            ->title('Категория удалена')
                            ->success()
                            ->send();
                    }),
            ])
            ->defaultSort('name');
    }
}
