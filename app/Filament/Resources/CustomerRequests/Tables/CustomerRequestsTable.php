<?php

namespace App\Filament\Resources\CustomerRequests\Tables;

use App\Enums\CustomerRequestStatus;
use App\Enums\ScenarioRunStatus;
use App\Models\CustomerRequest;
use App\Models\ScenarioRun;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Support\Icons\Heroicon;
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
                // Единственный ручной выход для залипшей заявки: поставщик
                // молчит или недостижим, а ожидание блокирует повторную
                // заявку заказчика по этому объявлению.
                Action::make('expire')
                    ->label('Закрыть без ответа')
                    ->icon(Heroicon::OutlinedNoSymbol)
                    ->color('gray')
                    ->visible(fn (CustomerRequest $record): bool => $record->status === CustomerRequestStatus::Pending)
                    ->requiresConfirmation()
                    ->modalHeading('Закрыть заявку без ответа?')
                    ->modalDescription('Ожидание ответа поставщика снимется, заказчик сможет отправить заявку по этому объявлению снова.')
                    ->action(function (CustomerRequest $record): void {
                        $record->expire();

                        // Активный опрос поставщика гаснет вместе с
                        // ожиданием: его кнопки должны отвечать «вопрос
                        // уже закрыт», а не «вы уже ответили».
                        ScenarioRun::query()
                            ->whereMorphedTo('subject', $record)
                            ->where('status', ScenarioRunStatus::Active)
                            ->get()
                            ->each(fn (ScenarioRun $run) => $run->finish());
                    }),
            ])
            ->defaultSort('created_at', 'desc');
    }
}
