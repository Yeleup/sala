<?php

namespace App\Filament\Resources\Listings\Schemas;

use App\Models\Listing;
use Filament\Infolists\Components\ImageEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ListingInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Объявление')
                    ->schema([
                        TextEntry::make('status')
                            ->label('Статус')
                            ->badge(),
                        TextEntry::make('type')
                            ->label('Тип')
                            ->badge(),
                        TextEntry::make('category.name')
                            ->label('Категория')
                            ->placeholder('Не указана'),
                        TextEntry::make('description')
                            ->label('Описание')
                            ->placeholder('Не указано')
                            ->columnSpanFull(),
                        TextEntry::make('location.name')
                            ->label('Локация')
                            ->state(fn (Listing $record): ?string => $record->location?->label())
                            ->placeholder('Не указана'),
                        TextEntry::make('location_detail')
                            ->label('Уточнение адреса')
                            ->placeholder('—'),
                        TextEntry::make('price')
                            ->label('Цена/Тариф')
                            ->placeholder('Не указана'),
                        TextEntry::make('expires_at')
                            ->label('Актуально до')
                            ->dateTime('d.m.Y H:i')
                            ->placeholder('—'),
                        TextEntry::make('rejection_reason')
                            ->label('Причина отклонения')
                            ->color('danger')
                            ->visible(fn (Listing $record): bool => filled($record->rejection_reason))
                            ->columnSpanFull(),
                    ])
                    ->columns(3),
                Section::make('Поставщик')
                    ->schema([
                        TextEntry::make('supplier.phone')
                            ->label('Телефон'),
                        TextEntry::make('supplier.profile_name')
                            ->label('Имя профиля')
                            ->placeholder('—'),
                        TextEntry::make('created_at')
                            ->label('Создано')
                            ->dateTime('d.m.Y H:i'),
                    ])
                    ->columns(3),
                Section::make('Медиа')
                    ->schema([
                        ImageEntry::make('photos.path')
                            ->label('Фото')
                            ->disk('public')
                            ->height(120)
                            ->placeholder('Нет фото'),
                        TextEntry::make('audioMessages.transcription')
                            ->label('Транскрипции аудио')
                            ->listWithLineBreaks()
                            ->placeholder('Нет аудио'),
                    ]),
            ]);
    }
}
