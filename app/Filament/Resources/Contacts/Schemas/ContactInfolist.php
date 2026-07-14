<?php

namespace App\Filament\Resources\Contacts\Schemas;

use App\Models\Contact;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ContactInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Контакт')
                    ->schema([
                        TextEntry::make('phone')
                            ->label('Телефон'),
                        TextEntry::make('display_name')
                            ->label('Отображаемое имя')
                            ->placeholder('—'),
                        TextEntry::make('profile_name')
                            ->label('Имя профиля WhatsApp')
                            ->placeholder('—'),
                        TextEntry::make('last_inbound_at')
                            ->label('Последнее входящее')
                            ->dateTime('d.m.Y H:i')
                            ->placeholder('Ещё не писал'),
                        IconEntry::make('session_window')
                            ->label('Окно 24 ч открыто')
                            ->boolean()
                            ->state(fn (Contact $record): bool => $record->hasOpenSessionWindow()),
                        TextEntry::make('created_at')
                            ->label('Первое обращение')
                            ->dateTime('d.m.Y H:i'),
                    ])
                    ->columns(2),
            ]);
    }
}
