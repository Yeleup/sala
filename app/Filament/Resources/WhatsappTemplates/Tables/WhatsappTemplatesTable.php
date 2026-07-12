<?php

namespace App\Filament\Resources\WhatsappTemplates\Tables;

use App\Enums\WhatsappTemplateCategory;
use App\Enums\WhatsappTemplateStatus;
use App\Models\WhatsappTemplate;
use App\Services\WhatsappTemplateRegistry;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Throwable;

class WhatsappTemplatesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Имя')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('language')
                    ->label('Язык'),
                TextColumn::make('category')
                    ->label('Категория')
                    ->badge(),
                TextColumn::make('status')
                    ->label('Статус')
                    ->badge(),
                TextColumn::make('rejection_reason')
                    ->label('Причина отклонения')
                    ->limit(60)
                    ->placeholder('—'),
                TextColumn::make('body')
                    ->label('Текст')
                    ->limit(60)
                    ->placeholder('—'),
                TextColumn::make('created_at')
                    ->label('Добавлен')
                    ->dateTime('d.m.Y H:i')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Статус')
                    ->options(WhatsappTemplateStatus::class),
                SelectFilter::make('category')
                    ->label('Категория')
                    ->options(WhatsappTemplateCategory::class),
            ])
            ->recordActions([
                Action::make('delete')
                    ->label('Удалить')
                    ->icon(Heroicon::OutlinedTrash)
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading('Удалить шаблон?')
                    ->modalDescription('Шаблон будет удалён и в Meta — отправлять его станет невозможно.')
                    ->action(function (WhatsappTemplate $record): void {
                        try {
                            app(WhatsappTemplateRegistry::class)->delete($record);
                        } catch (Throwable $e) {
                            Notification::make()
                                ->title('Не удалось удалить шаблон')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();

                            return;
                        }

                        Notification::make()
                            ->title('Шаблон удалён')
                            ->success()
                            ->send();
                    }),
            ])
            ->defaultSort('name');
    }
}
