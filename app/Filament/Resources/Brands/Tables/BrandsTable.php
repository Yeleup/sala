<?php

namespace App\Filament\Resources\Brands\Tables;

use App\Models\Brand;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class BrandsTable
{
    public static function configure(Table $table): Table
    {
        return $table
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
                    ->modalHeading('Удалить марку?')
                    ->modalDescription('AI и веб-формы перестанут предлагать её поставщикам.')
                    ->action(function (Brand $record): void {
                        if ($record->listings()->exists()) {
                            Notification::make()
                                ->title('Марку нельзя удалить')
                                ->body('Она используется в объявлениях — сначала уберите её из них.')
                                ->danger()
                                ->send();

                            return;
                        }

                        $record->delete();

                        Notification::make()
                            ->title('Марка удалена')
                            ->success()
                            ->send();
                    }),
            ])
            ->defaultSort('name');
    }
}
