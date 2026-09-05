<?php

namespace App\Filament\Resources\Listings\Schemas;

use App\Enums\LicenceType;
use App\Enums\ListingKind;
use App\Enums\ListingMediaType;
use App\Enums\RepairPlace;
use App\Filament\Resources\Brands\Schemas\BrandForm;
use App\Filament\Resources\Categories\Schemas\CategoryForm;
use App\Filament\Resources\Contacts\Schemas\ContactForm;
use App\Filament\Resources\Locations\Schemas\LocationForm;
use App\Models\Category;
use App\Models\Contact;
use App\Models\Listing;
use App\Models\ListingMedia;
use App\Models\Location;
use App\Services\Locations\LocationResolver;
use App\Support\PhoneNumber;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Arr;

/**
 * Operator form for a listing's business fields — also the moderation
 * screen, so it shows the lifecycle state and the collected media as
 * read-only next to the editable fields. The status itself is not an
 * editable field: transitions go through the dedicated actions (submit
 * for moderation, approve, reject) so the domain invariants hold.
 */
class ListingForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                // The publication rule as a live prompt. On a call this
                // doubles as the script of what is left to ask the
                // client. Saving an incomplete listing stays allowed —
                // only publication is gated.
                TextEntry::make('publication_readiness')
                    ->label('Готовность к публикации')
                    ->state(function (Get $get, ?Listing $record): string {
                        $missing = self::missingPublicationFields($get, $record);

                        return $missing === []
                            ? 'Все поля заполнены — объявление можно публиковать.'
                            : 'Не хватает для публикации: '.implode(', ', $missing).'.';
                    })
                    ->color(fn (Get $get, ?Listing $record): string => self::missingPublicationFields($get, $record) === [] ? 'success' : 'warning')
                    ->columnSpanFull(),
                TextEntry::make('status')
                    ->label('Статус')
                    ->badge()
                    ->hiddenOn('create'),
                TextEntry::make('expires_at')
                    ->label('Актуально до')
                    ->dateTime('d.m.Y H:i')
                    ->placeholder('—')
                    ->hiddenOn('create'),
                TextEntry::make('rejection_reason')
                    ->label('Причина отклонения')
                    ->color('danger')
                    ->visible(fn (?Listing $record): bool => filled($record?->rejection_reason))
                    ->columnSpanFull(),
                // The kind decides the questionnaire below. It is chosen
                // once, at creation: switching the kind of an existing
                // listing would leave the other kind's half-filled fields
                // behind as dirt in the record.
                Select::make('kind')
                    ->label('Вид')
                    ->options(collect(ListingKind::cases())->mapWithKeys(
                        fn (ListingKind $kind): array => [$kind->value => $kind->label()],
                    )->all())
                    ->default(ListingKind::Rental->value)
                    ->required()
                    ->selectablePlaceholder(false)
                    ->live()
                    ->disabledOn('edit')
                    ->helperText(fn (string $operation): ?string => $operation === 'edit' ? 'Вид задаётся при создании.' : null),
                Select::make('contact_id')
                    ->label('Поставщик')
                    ->relationship('supplier', 'phone')
                    ->getOptionLabelFromRecordUsing(fn (Contact $record): string => self::supplierLabel($record))
                    ->searchable()
                    ->getSearchResultsUsing(fn (string $search): array => self::supplierOptions($search))
                    ->noSearchResultsMessage('Такого поставщика ещё нет — нажмите + справа, чтобы завести.')
                    ->preload()
                    ->required()
                    // A client calling in for the first time must not cost
                    // the operator the form he has already typed, and on a
                    // call he rarely remembers whether this client has
                    // phoned before — so the same window both finds an
                    // existing supplier and creates a new one.
                    ->createOptionForm(fn (): array => [
                        Select::make('existing_contact_id')
                            ->label('Уже заведён?')
                            ->placeholder('Начните вводить номер или имя — покажем совпадения')
                            ->searchable()
                            ->live()
                            ->getSearchResultsUsing(fn (string $search): array => self::supplierOptions($search))
                            ->getOptionLabelUsing(function (mixed $value): ?string {
                                $contact = Contact::find($value);

                                return $contact === null ? null : self::supplierLabel($contact);
                            })
                            ->noSearchResultsMessage('Совпадений нет — заполните поля ниже.')
                            ->helperText('Нашли — выбирайте, и поля ниже заполнять не нужно.'),
                        // Hidden components are neither validated nor
                        // dehydrated, so choosing an existing supplier
                        // lifts the «телефон обязателен» requirement by
                        // itself and the window shows one job at a time.
                        Group::make(ContactForm::fields(rejectDuplicatePhone: false))
                            ->visible(fn (Get $get): bool => blank($get('existing_contact_id')))
                            ->columnSpanFull(),
                    ])
                    ->createOptionModalHeading('Поставщик')
                    ->createOptionAction(fn (Action $action): Action => $action->modalSubmitActionLabel('Готово'))
                    ->createOptionUsing(function (array $data): int {
                        if (filled($data['existing_contact_id'] ?? null)) {
                            return (int) $data['existing_contact_id'];
                        }

                        // The phone is the contact's identity, so a number
                        // already in the base means this supplier exists —
                        // he gets selected rather than the operator hitting
                        // a «такой номер уже есть» wall and starting over.
                        $existing = ContactForm::duplicateOf($data['phone'] ?? null);

                        if ($existing !== null) {
                            Notification::make()
                                ->title('Выбран существующий поставщик')
                                ->body('Этот номер уже заведён — новый контакт не создавался.')
                                ->warning()
                                ->send();

                            return $existing->getKey();
                        }

                        return Contact::create(Arr::except($data, 'existing_contact_id'))->getKey();
                    })
                    ->validationMessages(['required' => 'Выберите поставщика.']),
                TextInput::make('title')
                    ->label('Название')
                    ->placeholder('Например: Аренда автокрана 25 т')
                    ->maxLength(255)
                    // Text fields refresh the readiness hint on blur, not
                    // on every keystroke — one round trip per field.
                    ->live(onBlur: true),
                TextInput::make('person_name')
                    ->label(fn (Get $get): string => self::kindOf($get) === ListingKind::Repair ? 'Имя или название сервиса' : 'Имя')
                    ->placeholder(fn (Get $get): string => self::kindOf($get) === ListingKind::Repair
                        ? 'Например: Сервис «Мотор» или Асхат'
                        : 'Например: Серик')
                    ->maxLength(255)
                    ->live(onBlur: true)
                    ->visible(fn (Get $get): bool => self::kindOf($get) !== ListingKind::Rental),
                Select::make('category_id')
                    ->label('Категория')
                    ->relationship('category', 'name')
                    ->searchable()
                    ->preload()
                    ->live()
                    ->placeholder('Без категории')
                    ->visible(fn (Get $get): bool => self::kindOf($get) === ListingKind::Rental)
                    // The client names a trade the dictionary does not have
                    // yet: adding it here beats abandoning the form.
                    // ignoreRecord stays off in all three dictionary modals
                    // below: the record around them is the listing, not the
                    // dictionary row being created.
                    ->createOptionForm(fn (): array => [CategoryForm::nameField(ignoreRecord: false)])
                    ->createOptionModalHeading('Новая категория')
                    ->createOptionUsing(fn (array $data): int => Category::create($data)->getKey()),
                Select::make('brand_id')
                    ->label('Марка')
                    ->relationship('brand', 'name')
                    ->searchable()
                    ->preload()
                    ->placeholder('Без марки')
                    ->helperText('Производитель техники.')
                    ->createOptionForm(fn (): array => [BrandForm::nameField(ignoreRecord: false)])
                    ->createOptionModalHeading('Новая марка')
                    ->visible(fn (Get $get): bool => self::kindOf($get) === ListingKind::Rental),
                Textarea::make('services')
                    ->label('Услуги')
                    ->placeholder('Например: диагностика, ремонт двигателя, гидравлика, электрика')
                    ->rows(3)
                    ->maxLength(2000)
                    ->live(onBlur: true)
                    ->visible(fn (Get $get): bool => self::kindOf($get) === ListingKind::Repair)
                    ->columnSpanFull(),
                Select::make('repair_place')
                    ->label('Где ремонтирует')
                    ->options(collect(RepairPlace::cases())->mapWithKeys(
                        fn (RepairPlace $place): array => [$place->value => $place->label()],
                    )->all())
                    ->live()
                    ->visible(fn (Get $get): bool => self::kindOf($get) === ListingKind::Repair),
                Textarea::make('description')
                    ->label('Описание')
                    ->rows(4)
                    ->maxLength(2000)
                    ->live(onBlur: true)
                    ->columnSpanFull(),
                Select::make('location_id')
                    ->label('Локация')
                    ->searchable()
                    ->live()
                    ->placeholder('Поиск: город, район или село')
                    // An empty field opens the top of the KATO tree — the
                    // cities of republican significance and the oblasts —
                    // so a place can be picked without typing, exactly as
                    // the public form already does (LocationSearchController).
                    ->options(fn (): array => self::locationOptions(app(LocationResolver::class)->topLevel()))
                    ->getSearchResultsUsing(fn (string $search): array => self::locationOptions(
                        app(LocationResolver::class)->suggest($search, 20),
                    ))
                    ->getOptionLabelUsing(fn (mixed $value): ?string => Location::find($value)?->label())
                    // A new microdistrict or a settlement KATO skipped: the
                    // place is added inside an existing node, so the subtree
                    // search the customer relies on keeps working.
                    ->createOptionForm(fn (): array => [
                        LocationForm::nameField(ignoreRecord: false),
                        LocationForm::parentField()
                            ->required()
                            ->helperText('Место заводится внутрь существующего узла справочника.')
                            ->validationMessages(['required' => 'Выберите, внутри какого места оно находится.']),
                    ])
                    ->createOptionModalHeading('Новое место')
                    ->createOptionUsing(fn (array $data): int => Location::create($data)->getKey()),
                TextInput::make('location_detail')
                    ->label('Уточнение адреса')
                    ->placeholder('Например: центр, мкр Нурсат')
                    ->maxLength(255),
                TextInput::make('price')
                    // For a repair the price field carries one number the
                    // customer compares by — what the diagnostics visit costs.
                    ->label(fn (Get $get): string => self::kindOf($get) === ListingKind::Repair ? 'Цена за диагностику' : 'Цена/Тариф')
                    ->placeholder('Например: 10000 тг/ч')
                    ->maxLength(255)
                    ->live(onBlur: true)
                    ->visible(fn (Get $get): bool => self::kindOf($get) !== ListingKind::Driver),
                Select::make('machine_categories')
                    ->label('Техника, на которой работает')
                    ->relationship('machineCategories', 'name')
                    ->multiple()
                    ->searchable()
                    ->preload()
                    ->visible(fn (Get $get): bool => self::kindOf($get) === ListingKind::Driver),
                // Machinery the driver named that the dictionary lacks, in
                // his own words: the bot keeps it rather than repeating the
                // same question until the attempt limit runs out. It does
                // not block publication — the operator adds the category on
                // moderation, ticks it above and clears this line.
                TextInput::make('unlisted_machinery')
                    ->label('Техника вне справочника')
                    ->helperText('Водитель назвал технику, которой нет в справочнике. Заведите категорию, отметьте её в поле выше и очистите эту строку.')
                    ->maxLength(120)
                    ->visible(fn (Get $get): bool => self::kindOf($get) === ListingKind::Driver),
                Select::make('licence_type')
                    ->label('Тип удостоверения')
                    ->options(collect(LicenceType::cases())->mapWithKeys(
                        fn (LicenceType $type): array => [$type->value => $type->label()],
                    )->all())
                    ->live()
                    ->visible(fn (Get $get): bool => self::kindOf($get) === ListingKind::Driver),
                TextInput::make('experience_years')
                    ->label('Стаж, лет')
                    ->numeric()
                    ->minValue(0)
                    ->maxValue(80)
                    ->live(onBlur: true)
                    ->visible(fn (Get $get): bool => self::kindOf($get) === ListingKind::Driver),
                Toggle::make('travels_to_other_cities')
                    ->label('Готов выезжать в другие города')
                    ->live()
                    ->visible(fn (Get $get): bool => self::kindOf($get) === ListingKind::Driver),
                Repeater::make('photos')
                    ->label('Фотографии')
                    ->relationship()
                    ->schema([
                        FileUpload::make('path')
                            ->hiddenLabel()
                            ->disk('public')
                            ->directory('listing-photos')
                            ->image()
                            ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
                            ->maxSize(ListingMedia::MAX_PHOTO_KILOBYTES)
                            ->required()
                            ->validationMessages(['required' => 'Загрузите фото или удалите пустую строку.']),
                    ])
                    // The photos() relation filters by type but does not set
                    // it on create — new rows must be stamped explicitly.
                    ->mutateRelationshipDataBeforeCreateUsing(function (array $data): array {
                        $data['type'] = ListingMediaType::Photo->value;

                        return $data;
                    })
                    ->maxItems(Listing::MAX_PHOTOS)
                    ->defaultItems(0)
                    ->addActionLabel('Добавить фото')
                    ->reorderable(false)
                    ->columnSpanFull(),
                // The driver's licence document lives on the non-public
                // disk and never renders to customers — the operator views
                // it through the authenticated document route.
                TextEntry::make('document')
                    ->label('Документ водителя')
                    ->state('Открыть документ')
                    ->url(fn (?Listing $record): ?string => $record === null ? null : route('moderation.listings.document', $record))
                    ->openUrlInNewTab()
                    ->visible(fn (Get $get, ?Listing $record): bool => self::kindOf($get) === ListingKind::Driver
                        && $record !== null
                        && $record->documents()->exists()),
                // A virtual field: EditListing turns it into the
                // document_verified_at/by audit columns on save.
                Toggle::make('document_verified')
                    ->label('Документ проверен')
                    ->helperText('Отметка фиксирует, кто и когда сверил снимок удостоверения с анкетой.')
                    ->hiddenOn('create')
                    ->visible(fn (Get $get): bool => self::kindOf($get) === ListingKind::Driver),
                // Audio the supplier sent is not editable, but its
                // transcription is what the operator moderates by.
                TextEntry::make('audioMessages.transcription')
                    ->label('Транскрипции аудио')
                    ->listWithLineBreaks()
                    ->placeholder('Нет аудио')
                    ->columnSpanFull()
                    ->hiddenOn('create'),
            ]);
    }

    private static function supplierLabel(Contact $contact): string
    {
        return filled($contact->displayName())
            ? "{$contact->phone} ({$contact->displayName()})"
            : $contact->phone;
    }

    /**
     * Supplier lookup by name or by a number in any spelling: a number
     * dictated on a call as «8 701…» has to find the contact WhatsApp
     * stored as «7701…», otherwise the operator creates a duplicate and
     * the listing ends up on a number nothing can reach.
     *
     * Backs both the «Поставщик» field and the picker inside its modal, so
     * the two behave identically.
     *
     * @return array<int, string>
     */
    public static function supplierOptions(string $search): array
    {
        $prefixes = PhoneNumber::searchPrefixes($search);

        return Contact::query()
            ->where(function (Builder $query) use ($search, $prefixes): void {
                $query
                    ->where('profile_name', 'ilike', '%'.$search.'%')
                    ->orWhere('display_name', 'ilike', '%'.$search.'%');

                foreach ($prefixes as $prefix) {
                    $query->orWhere('phone', 'like', $prefix.'%');
                }
            })
            ->orderBy('phone')
            ->limit(20)
            ->get()
            ->mapWithKeys(fn (Contact $contact): array => [$contact->id => self::supplierLabel($contact)])
            ->all();
    }

    /**
     * Place options labelled by their full KATO chain («место, район,
     * область») — namesakes are told apart by their parents. The chains
     * are built for the whole list in one query rather than per row.
     *
     * @param  Collection<int, Location>  $locations
     * @return array<int, string>
     */
    private static function locationOptions(Collection $locations): array
    {
        // chainsFor() gives the ancestors only, and an empty string for
        // top-level nodes — the node's own name goes in front of it.
        $chains = Location::chainsFor($locations);

        return $locations
            ->mapWithKeys(fn (Location $location): array => [
                $location->id => $chains[$location->id] === ''
                    ? $location->name
                    : $location->name.', '.$chains[$location->id],
            ])
            ->all();
    }

    /**
     * The kind driving the questionnaire, read off the live form state.
     * fromNode() falls back to rental, so a create form freshly opened
     * behaves as a rental one until the operator picks otherwise.
     */
    private static function kindOf(Get $get): ListingKind
    {
        return ListingKind::fromNode($get('kind'));
    }

    /**
     * The publication fields still blank in the form as it stands right
     * now — the same rule Listing::publish() enforces, read off the live
     * form state instead of the saved record.
     *
     * @return list<string>
     */
    private static function missingPublicationFields(Get $get, ?Listing $record): array
    {
        $kind = self::kindOf($get);

        $values = [];

        foreach (array_keys($kind->publicationFields()) as $field) {
            $values[$field] = $get($field);
        }

        $missing = Listing::missingPublicationFields($kind, $values);

        // The licence document arrives only in the chat with the bot —
        // the form cannot attach it, so the saved record is the source.
        if ($kind->requiresDocument() && ! $record?->documents()->exists()) {
            $missing[] = 'фото документа';
        }

        return $missing;
    }
}
