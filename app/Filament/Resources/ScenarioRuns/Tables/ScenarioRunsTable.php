<?php

namespace App\Filament\Resources\ScenarioRuns\Tables;

use App\Enums\ScenarioRunStatus;
use App\Models\CustomerRequest;
use App\Models\Listing;
use App\Models\ScenarioRun;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class ScenarioRunsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')
                    ->label('№')
                    ->sortable(),
                TextColumn::make('scenario.name')
                    ->label('Сценарий'),
                TextColumn::make('scenario_version')
                    ->label('Версия'),
                TextColumn::make('contact.phone')
                    ->label('Контакт')
                    ->searchable(),
                TextColumn::make('subject_type')
                    ->label('Предмет')
                    ->formatStateUsing(fn (?string $state, ScenarioRun $record): string => match ($state) {
                        CustomerRequest::class => 'Заявка №'.$record->subject_id,
                        Listing::class => 'Объявление №'.$record->subject_id,
                        default => '—',
                    })
                    ->placeholder('—'),
                TextColumn::make('status')
                    ->label('Статус')
                    ->badge(),
                TextColumn::make('current_node_id')
                    ->label('Ожидающий блок')
                    ->placeholder('—'),
                TextColumn::make('timeout_at')
                    ->label('Таймаут')
                    ->dateTime('d.m.Y H:i')
                    ->placeholder('—'),
                TextColumn::make('created_at')
                    ->label('Запущен')
                    ->dateTime('d.m.Y H:i')
                    ->sortable(),
                TextColumn::make('updated_at')
                    ->label('Обновлён')
                    ->dateTime('d.m.Y H:i')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Статус')
                    ->options(ScenarioRunStatus::class),
                SelectFilter::make('bot_scenario_id')
                    ->label('Сценарий')
                    ->relationship('scenario', 'name'),
            ])
            ->defaultSort('id', 'desc');
    }
}
