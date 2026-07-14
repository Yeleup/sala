<?php

namespace App\Filament\Resources\Contacts\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

/**
 * Operator form for a contact. Only the identity fields are editable:
 * the last-inbound timestamp is system data driven by real WhatsApp
 * messages, so the 24-hour window cannot be forged from the admin.
 */
class ContactForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('phone')
                    ->label('Телефон')
                    ->placeholder('77011234567')
                    ->helperText('Только цифры, в международном формате без «+».')
                    ->required()
                    ->rule('regex:/^\d{6,15}$/')
                    ->unique(ignoreRecord: true)
                    ->validationMessages([
                        'required' => 'Укажите номер телефона.',
                        'regex' => 'Только цифры, в международном формате без «+».',
                        'unique' => 'Контакт с таким номером уже есть.',
                    ]),
                TextInput::make('profile_name')
                    ->label('Имя профиля')
                    ->placeholder('Как контакт подписан в WhatsApp')
                    ->maxLength(255),
            ]);
    }
}
