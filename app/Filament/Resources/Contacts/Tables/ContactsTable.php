<?php

namespace App\Filament\Resources\Contacts\Tables;

use App\Models\Contact;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ContactsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('phone')
                    ->label('Телефон')
                    ->searchable(),
                TextColumn::make('profile_name')
                    ->label('Имя профиля')
                    ->searchable()
                    ->placeholder('—'),
                TextColumn::make('last_inbound_at')
                    ->label('Последнее входящее')
                    ->dateTime('d.m.Y H:i')
                    ->sortable()
                    ->placeholder('—'),
                IconColumn::make('session_window')
                    ->label('Окно 24 ч')
                    ->boolean()
                    ->state(fn (Contact $record): bool => $record->hasOpenSessionWindow()),
                TextColumn::make('listings_count')
                    ->label('Объявлений')
                    ->counts('listings'),
                TextColumn::make('customer_requests_count')
                    ->label('Заявок')
                    ->counts('customerRequests'),
            ])
            ->recordActions([
                ViewAction::make(),
            ])
            ->defaultSort('id', 'desc');
    }
}
