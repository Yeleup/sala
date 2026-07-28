<?php

namespace App\Filament\Resources\Contacts\Schemas;

use App\Models\Contact;
use App\Support\PhoneNumber;
use Closure;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

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
                    ->helperText('Записывайте как удобно — «8 701…», «+7 701…»; номер сам приводится к виду 77011234567.')
                    ->required()
                    // The operator writes the number down as it is
                    // dictated on a call; it is canonicalized in place so
                    // both the duplicate check below and the saved value
                    // work off the one form WhatsApp names the contact by.
                    ->live(onBlur: true)
                    ->afterStateUpdated(fn (Set $set, ?string $state): mixed => $set('phone', PhoneNumber::normalize($state)))
                    ->rule('regex:/^\d{6,15}$/')
                    ->rule(static fn (?Model $record): Closure => static function (string $attribute, mixed $value, Closure $fail) use ($record): void {
                        $duplicate = Contact::query()
                            ->where('phone', $value)
                            ->when(
                                $record instanceof Contact,
                                fn (Builder $query): Builder => $query->whereKeyNot($record->getKey()),
                            )
                            ->first();

                        if ($duplicate === null) {
                            return;
                        }

                        // Naming the existing contact turns a dead end
                        // into an answer: on a call the operator learns
                        // whose number the client just dictated.
                        $fail(filled($duplicate->displayName())
                            ? 'Контакт с таким номером уже есть — «'.$duplicate->displayName().'».'
                            : 'Контакт с таким номером уже есть.');
                    })
                    ->validationMessages([
                        'required' => 'Укажите номер телефона.',
                        'regex' => 'Похоже, это не номер телефона — укажите номер в международном формате.',
                    ]),
                TextInput::make('display_name')
                    ->label('Отображаемое имя')
                    ->placeholder('Имя, заданное вручную')
                    ->helperText('Показывается вместо имени профиля WhatsApp; поставщик задаёт его сам в веб-кабинете. Пустое поле — используется имя профиля.')
                    ->maxLength(255)
                    ->dehydrateStateUsing(fn (?string $state): ?string => trim((string) $state) ?: null),
                TextInput::make('profile_name')
                    ->label('Имя профиля')
                    ->placeholder('Как контакт подписан в WhatsApp')
                    ->helperText('Обновляется автоматически с каждым входящим сообщением WhatsApp.')
                    ->maxLength(255),
            ]);
    }
}
