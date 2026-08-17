<?php

namespace App\Filament\Resources\Contacts\Schemas;

use App\Models\Contact;
use App\Support\PhoneNumber;
use Closure;
use Filament\Forms\Components\TextInput;
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
    private const string PHONE_FORMAT_HINT = 'Формат +7(7XX)XXXXXXX — код страны и первая семёрка уже подставлены, диктуемую «восьмёрку» набирать не нужно.';

    /**
     * `$rejectDuplicatePhone` off means the caller resolves an already
     * known number itself instead of failing validation — that is what the
     * «новый поставщик» modal of the listing form does: there a known
     * number is an answer (this supplier already exists), not an error.
     */
    public static function configure(Schema $schema, bool $rejectDuplicatePhone = true): Schema
    {
        return $schema->components(self::fields($rejectDuplicatePhone));
    }

    /**
     * The identity fields on their own, so the listing form can compose
     * them into a modal that also offers picking an existing contact.
     *
     * @return list<TextInput>
     */
    public static function fields(bool $rejectDuplicatePhone = true): array
    {
        return [
            self::phoneField($rejectDuplicatePhone),
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
        ];
    }

    /**
     * The contact already holding this number, if any. The lookup goes by
     * the canonical form, so a number dictated as «8 701…» finds the
     * contact WhatsApp stored as «7701…». `$record` excludes the contact
     * being edited from being reported as its own duplicate.
     */
    public static function duplicateOf(mixed $phone, ?Model $record = null): ?Contact
    {
        $normalized = PhoneNumber::normalize(is_string($phone) ? $phone : null);

        if ($normalized === null) {
            return null;
        }

        return Contact::query()
            ->where('phone', $normalized)
            ->when(
                $record instanceof Contact,
                fn (Builder $query): Builder => $query->whereKeyNot($record->getKey()),
            )
            ->first();
    }

    /**
     * The Kazakh number shape the field types under. The country code and
     * the leading 7 of the area code are literal — every Kazakh number
     * starts with them, and showing them spares the operator the «8 or 7»
     * question while the client dictates.
     */
    private const string KAZAKH_MASK = '+7(799)9999999';

    /**
     * The mask gives the number its shape as it is typed; the canonical
     * form is derived from it twice, each time for a different reason:
     * before validation, so the duplicate check and the unique index agree
     * on what «the same number» means, and on dehydration, so nothing but
     * digits ever reaches the column.
     *
     * The mask steps aside for a foreign number — those arrive from
     * WhatsApp and must stay editable as they are.
     */
    private static function phoneField(bool $rejectDuplicate): TextInput
    {
        $field = TextInput::make('phone')
            ->label('Телефон')
            ->required()
            ->live(onBlur: true)
            ->mask(fn (?string $state): ?string => PhoneNumber::fitsKazakhMask($state) ? self::KAZAKH_MASK : null)
            ->mutateStateForValidationUsing(fn (?string $state): ?string => PhoneNumber::normalize($state))
            ->dehydrateStateUsing(fn (?string $state): ?string => PhoneNumber::normalize($state))
            ->helperText(fn (?string $state, ?Model $record): string => self::phoneHint($state, $record, $rejectDuplicate))
            ->rule('regex:/^\d{6,15}$/')
            // A country-code-7 number is always eleven digits, and a mask
            // makes a half-typed one easy to submit without noticing.
            ->rule(static fn (): Closure => static function (string $attribute, mixed $value, Closure $fail): void {
                $digits = (string) $value;

                if (str_starts_with($digits, '7') && strlen($digits) !== PhoneNumber::KAZAKH_LENGTH) {
                    $fail('Номер не дописан — в казахстанском номере 11 цифр, формат +7(7XX)XXXXXXX.');
                }
            })
            ->validationMessages([
                'required' => 'Укажите номер телефона.',
                'regex' => 'Похоже, это не номер телефона — укажите номер в международном формате.',
            ]);

        if (! $rejectDuplicate) {
            return $field;
        }

        return $field->rule(static fn (?Model $record): Closure => static function (string $attribute, mixed $value, Closure $fail) use ($record): void {
            $duplicate = self::duplicateOf($value, $record);

            if ($duplicate === null) {
                return;
            }

            // Naming the existing contact turns a dead end into an answer:
            // on a call the operator learns whose number was just dictated.
            $fail('Контакт с таким номером уже есть — '.self::nameOf($duplicate).'.');
        });
    }

    /**
     * The hint doubles as the duplicate warning: the field refreshes on
     * blur, so the operator learns the number is already known before he
     * submits, not after.
     */
    private static function phoneHint(?string $state, ?Model $record, bool $rejectDuplicate): string
    {
        $duplicate = self::duplicateOf($state, $record);

        if ($duplicate === null) {
            return self::PHONE_FORMAT_HINT;
        }

        return $rejectDuplicate
            ? 'Контакт с таким номером уже есть — '.self::nameOf($duplicate).'.'
            : 'Этот номер уже заведён — '.self::nameOf($duplicate).'. Он и будет выбран поставщиком, дубль не создастся.';
    }

    private static function nameOf(Contact $contact): string
    {
        return filled($contact->displayName()) ? '«'.$contact->displayName().'»' : 'контакт без имени';
    }
}
