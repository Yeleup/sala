# Виды объявлений (аренда / ремонт / водитель): план реализации

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Три вида объявлений со своими анкетами в боте, своими ветками поиска, своими карточками, обязательным непубличным фото удостоверения у водителя и операторской галочкой «Документ проверен».

**Architecture:** Вид (`ListingKind`) — enum-описание с методами: обязательные поля публикации, поля сбора, лимит уточнений, кнопочные дозапросы, тексты. Из него выводятся схема/промпт `ListingExtractionAgent`, проверки `SupplierListingCollector`, гейт публикации `Listing`, формы админки и веб-кабинета, карточки и фильтры. Вид ставится настройкой AI-блока сценария (`node['kind']`), меню становится интерактивным списком на 6 пунктов. Поиск фильтрует по виду в SQL. Фото документа — новый непубличный тип медиа.

**Tech Stack:** PHP 8.4, Laravel 13, laravel/ai v0 (`HasStructuredOutput`, `#[Strict]`), Filament 5 + Alpine (редактор сценариев), Pest 4, pgvector.

Спека: [docs/superpowers/specs/2026-08-11-listing-kinds-design.md](../specs/2026-08-11-listing-kinds-design.md)

## Global Constraints

- Все PHP/Composer/npm-команды — только внутри Docker: `make artisan`, `make composer`, `make shell`. Хост-PHP не использовать.
- Тесты — только `make test test_args="--compact --filter=..."`. Никогда `php artisan test` (даже через `docker exec`) — он ходит в dev-базу.
- После правки PHP: `make shell` → `vendor/bin/pint --dirty --format agent`.
- Значения enum вида: ровно `rental`, `repair`, `driver`. Неизвестный/пустой вид всюду читается как `rental` (фолбэк, как у `AiTask::fromNode`).
- Строгая схема AI: каждый ключ — `->required()`, отсутствие данных — только `->nullable()` (тест `AiAgentStrictSchemaTest` проверяет все агенты автоматически).
- Лимиты WhatsApp: 3 кнопки, 10 строк списка, заголовок кнопки 20 символов, строки списка 24, описание строки 72.
- Кнопочные дозапросы и просьба о документе НЕ тратят попытку уточнения; один и тот же кнопочный вопрос уходит максимум 2 раза.
- Фото документа хранится на диске `local` (непубличный), НИКОГДА не попадает в каталог, галерею, кабинет поставщика и вложения к AI-вызову.
- Комментарии в коде — на английском; тексты пользователю и документация — на русском.
- Правило проекта: изменение storefront-вёрстки зеркалится в `resources/views/storefront-design-preview.blade.php`; изменение бизнес-логики — в `docs/` тем же коммитом (финальная сводка доков — Task 14, но правки модульных доков делаются в задачах, где меняется поведение).
- `tools/demo-video`: поля формы админки ищутся по имени — не переименовывать существующие поля; после Task 12 прогнать `make demo-video` (без перезаписи озвучки).
- Флак-профилактика: в тестах матчера/эмбеддингов НЕ полагаться на случайные `description`/`price` из `ListingFactory` — задавать явно.

---

### Task 1: `ListingKind`, новые enum'ы, миграция и модель

**Files:**
- Create: `app/Enums/ListingKind.php`
- Create: `app/Enums/RepairPlace.php`
- Create: `app/Enums/LicenceType.php`
- Create: `database/migrations/2026_08_1x_000000_add_kind_fields_to_listings.php` (имя сгенерирует `make:migration`)
- Modify: `app/Enums/ListingMediaType.php`
- Modify: `app/Models/Listing.php` (fillable, casts, relations)
- Modify: `database/factories/ListingFactory.php`
- Test: `tests/Feature/ListingKindTest.php` (новый)

**Interfaces:**
- Produces (используют ВСЕ последующие задачи):

```php
enum ListingKind: string
{
    case Rental = 'rental';
    case Repair = 'repair';
    case Driver = 'driver';

    public static function fromNode(mixed $value): self;      // null/мусор → Rental
    public function label(): string;                          // «Аренда спецтехники» / «Ремонт спецтехники» / «Водитель / машинист»
    /** @return array<string, string> поле листинга => русская метка (для гейта публикации) */
    public function publicationFields(): array;
    /** @return list<string> ключи извлечения, без которых сбор не завершён */
    public function collectorRequiredFields(): array;
    public function maxClarifications(): int;                 // 3 / 4 / 6
    public function requiresDocument(): bool;                 // только Driver
    /** @return array<string, array{question: string, options: array<string, string>}> поле => кнопочный вопрос */
    public function buttonFields(): array;
    /** @return array<string, string> поле => статический фолбэк-вопрос */
    public function fallbackQuestions(): array;
    public function greeting(): string;                       // приветствие AI-блока по умолчанию
}

enum RepairPlace: string { case OwnService='own_service'; case Travels='travels'; case Both='both'; public function label(): string; }
enum LicenceType: string { case DriverLicence='driver_licence'; case TractorOperator='tractor_operator'; case Other='other'; public function label(): string; }
// ListingMediaType: + case Document = 'document';
// Listing: + kind, person_name, services, experience_years, repair_place, travels_to_other_cities,
//   licence_type, document_verified_at, document_verified_by; relations machineCategories(), documents(), documentVerifier().
```

Содержимое методов `ListingKind` (единственный источник правды, всё из спеки):

```php
public function publicationFields(): array
{
    return match ($this) {
        self::Rental => [
            'title' => 'название', 'category_id' => 'категория', 'description' => 'описание',
            'location_id' => 'локация', 'price' => 'цена',
        ],
        self::Repair => [
            'title' => 'название', 'person_name' => 'имя или название сервиса', 'services' => 'услуги',
            'repair_place' => 'где ремонтирует', 'location_id' => 'локация',
        ],
        self::Driver => [
            'title' => 'название', 'person_name' => 'имя', 'licence_type' => 'тип удостоверения',
            'experience_years' => 'стаж', 'location_id' => 'локация',
            'travels_to_other_cities' => 'готовность выезжать',
        ],
    };
}

public function collectorRequiredFields(): array
{
    return match ($this) {
        self::Rental => ['category', 'description', 'location_id', 'price'],
        self::Repair => ['person_name', 'services', 'repair_place', 'location_id'],
        self::Driver => ['person_name', 'machine_categories', 'licence_type',
            'experience_years', 'location_id', 'travels_to_other_cities'],
    };
}

public function maxClarifications(): int
{
    return match ($this) { self::Rental => 3, self::Repair => 4, self::Driver => 6 };
}

public function buttonFields(): array
{
    return match ($this) {
        self::Rental => [],
        self::Repair => [
            'repair_place' => [
                'question' => 'Где вы выполняете ремонт?',
                'options' => ['own_service' => 'В своём сервисе', 'travels' => 'С выездом', 'both' => 'И так, и так'],
            ],
        ],
        self::Driver => [
            'licence_type' => [
                'question' => 'Какое у вас удостоверение?',
                'options' => ['driver_licence' => 'Водительское', 'tractor_operator' => 'Тракторист-машинист', 'other' => 'Другой документ'],
            ],
            'travels_to_other_cities' => [
                'question' => 'Готовы выезжать на работу в другие города?',
                'options' => ['yes' => 'Да', 'no' => 'Нет'],
            ],
        ],
    };
}
```

(`fallbackQuestions()`: rental — как сегодня в `clarificationQuestion()`; repair — `person_name` «Как вас зовут или как называется ваш сервис?», `services` «Какие работы вы выполняете? Например: диагностика, ремонт двигателя, гидравлика, электрика, сварочные работы.», `repair_place` и `location_id` по образцу; driver — `machine_categories` «На какой технике вы работаете — экскаватор, самосвал, кран?», `experience_years` «Сколько лет вы работаете на этой технике?» и т.д. `greeting()`: rental — сегодняшний текст из `SupplierListingCollector::start()`; repair — «Расскажите о себе: какие работы выполняете, в своём сервисе или с выездом, в каком городе, сколько стоит диагностика.»; driver — «Расскажите о себе: на какой технике работаете, какое удостоверение, сколько лет стажа, в каком городе, готовы ли выезжать.»)

**Steps:**

- [ ] **Step 1: Миграция.** `make artisan artisan_args="make:migration add_kind_fields_to_listings --no-interaction"`. Содержимое:

```php
public function up(): void
{
    Schema::table('listings', function (Blueprint $table) {
        $table->string('kind', 16)->default('rental')->index();
        $table->string('person_name')->nullable();
        $table->text('services')->nullable();
        $table->unsignedSmallInteger('experience_years')->nullable();
        $table->string('repair_place', 16)->nullable();
        $table->boolean('travels_to_other_cities')->nullable();
        $table->string('licence_type', 32)->nullable();
        $table->timestamp('document_verified_at')->nullable();
        $table->foreignId('document_verified_by')->nullable()->constrained('users')->nullOnDelete();
    });

    Schema::create('category_listing', function (Blueprint $table) {
        $table->id();
        $table->foreignId('listing_id')->constrained()->cascadeOnDelete();
        $table->foreignId('category_id')->constrained()->restrictOnDelete();
        $table->unique(['listing_id', 'category_id']);
    });
}
```

`down()`: `dropConstrainedForeignId('document_verified_by')`, drop остальных колонок, `Schema::dropIfExists('category_listing')`. Бэкфилл не нужен: default `'rental'` покрывает существующие 37 строк.

- [ ] **Step 2: Написать падающие тесты** `tests/Feature/ListingKindTest.php`:

```php
<?php

use App\Enums\LicenceType;
use App\Enums\ListingKind;
use App\Enums\ListingMediaType;
use App\Models\Listing;
use App\Models\ListingMedia;

test('вид по умолчанию — аренда, и фолбэк из мусора — аренда', function () {
    expect(Listing::factory()->create()->kind)->toBe(ListingKind::Rental)
        ->and(ListingKind::fromNode(null))->toBe(ListingKind::Rental)
        ->and(ListingKind::fromNode('garbage'))->toBe(ListingKind::Rental)
        ->and(ListingKind::fromNode('driver'))->toBe(ListingKind::Driver);
});

test('поля публикации у каждого вида свои, и у водителя нет цены', function () {
    expect(array_keys(ListingKind::Rental->publicationFields()))
        ->toBe(['title', 'category_id', 'description', 'location_id', 'price'])
        ->and(array_keys(ListingKind::Driver->publicationFields()))->not->toContain('price', 'category_id')
        ->and(array_keys(ListingKind::Repair->publicationFields()))->not->toContain('price');
});

test('водитель хранит технику связью, а документ — непубличным медиа', function () {
    $listing = Listing::factory()->create([
        'kind' => ListingKind::Driver, 'licence_type' => LicenceType::TractorOperator,
    ]);
    $listing->machineCategories()->sync([categoryNamed('Экскаватор')->id, categoryNamed('Самосвал')->id]);
    ListingMedia::create([
        'listing_id' => $listing->id, 'type' => ListingMediaType::Document,
        'disk' => 'local', 'path' => 'listings/1/documents/doc.jpg',
    ]);

    expect($listing->machineCategories()->pluck('name')->sort()->values()->all())
        ->toBe(['Самосвал', 'Экскаватор'])
        ->and($listing->documents()->count())->toBe(1)
        ->and($listing->photos()->count())->toBe(0);   // документ — не фото
});
```

- [ ] **Step 3: Прогнать — убедиться, что падают.** `make test test_args="--compact --filter=ListingKindTest"` — FAIL (нет enum/колонок).

- [ ] **Step 4: Реализовать.** Создать три enum'а (полные из Interfaces). В `ListingMediaType` добавить `case Document = 'document';`. В `Listing`:
  - `#[Fillable]`: добавить `kind`, `person_name`, `services`, `experience_years`, `repair_place`, `travels_to_other_cities`, `licence_type`, `document_verified_at`, `document_verified_by`;
  - `casts()`: `'kind' => ListingKind::class, 'repair_place' => RepairPlace::class, 'licence_type' => LicenceType::class, 'travels_to_other_cities' => 'boolean', 'document_verified_at' => 'datetime'`;
  - `protected $attributes`: добавить `'kind' => ListingKind::Rental->value` (рядом со status);
  - связи:

```php
/** @return BelongsToMany<Category, $this> Machines a driver operates. */
public function machineCategories(): BelongsToMany
{
    return $this->belongsToMany(Category::class);
}

/** @return HasMany<ListingMedia, $this> The non-public licence document. */
public function documents(): HasMany
{
    return $this->media()->where('type', ListingMediaType::Document);
}

/** @return BelongsTo<User, $this> */
public function documentVerifier(): BelongsTo
{
    return $this->belongsTo(User::class, 'document_verified_by');
}
```

  В `ListingFactory` — состояния `repair()` и `driver()` (kind + заполненные поля вида, явные `description`/`price`, где нужны).

- [ ] **Step 5: Прогнать тесты нового файла и смежные.** `make test test_args="--compact --filter=ListingKindTest"` — PASS; затем `make test test_args="--compact --filter=ListingLifecycleTest"` — PASS (гейт публикации ещё старый, вид по умолчанию rental ничего не меняет).

- [ ] **Step 6: Pint + коммит** `git commit -m "Вид объявления: enum, колонки, связи техники и документа"`.

---

### Task 2: Гейт публикации — из описания вида

**Files:**
- Modify: `app/Models/Listing.php` (`PUBLICATION_FIELDS` → per-kind, `missingPublicationFields`, `missingForPublication`)
- Modify: `tests/Feature/ListingLifecycleTest.php` (тест-замок «админка == веб-форма»)
- Test: `tests/Feature/ListingLifecycleTest.php`

**Interfaces:**
- Consumes: `ListingKind::publicationFields()`, `requiresDocument()`, `Listing::documents()` (Task 1).
- Produces:

```php
// Listing:
/** @return array<string, string> */
public function publicationFields(): array;                       // $this->kind->publicationFields()
public static function missingPublicationFields(ListingKind $kind, array $values): array; // сигнатура МЕНЯЕТСЯ: + $kind первым аргументом
public function missingForPublication(): array;                   // + «фото документа», если requiresDocument() и documents()->doesntExist()
```

Константа `PUBLICATION_FIELDS` удаляется. Consumers: `ListingForm::314-323` (правится в Task 12), `ListingResource::publish` action (читает `isReadyForPublication()` — не меняется), `UpdateSupplierListingRequest` (Task 13).

**Steps:**

- [ ] **Step 1: Написать падающие тесты** (в `ListingLifecycleTest.php`):

```php
test('гейт публикации зависит от вида', function () {
    $driver = Listing::factory()->create([
        'kind' => ListingKind::Driver, 'title' => 'Машинист экскаватора',
        'person_name' => 'Иван', 'licence_type' => LicenceType::TractorOperator,
        'experience_years' => 8, 'location_id' => locationNamed('Алматы')->id,
        'travels_to_other_cities' => true,
    ]);

    // Всё скалярное есть, документа нет — публиковать нельзя.
    expect($driver->missingForPublication())->toBe(['фото документа']);

    ListingMedia::create(['listing_id' => $driver->id, 'type' => ListingMediaType::Document,
        'disk' => 'local', 'path' => 'x.jpg']);

    expect($driver->fresh()->isReadyForPublication())->toBeTrue()
        ->and($driver->fresh()->missingForPublication())->toBe([]);
});

test('false в булевом поле — это ответ, а не пробел', function () {
    // blank(false) === true, поэтому наивный blank() потерял бы «не готов выезжать».
    $missing = Listing::missingPublicationFields(ListingKind::Driver, [
        'title' => 'т', 'person_name' => 'и', 'licence_type' => 'other',
        'experience_years' => 1, 'location_id' => 5, 'travels_to_other_cities' => false,
    ]);

    expect($missing)->toBe([]);
});
```

(`locationNamed()` — если такого хелпера в `tests/Pest.php` нет, создать по образцу `categoryNamed()`: `Location::factory()->firstOrCreate(['name' => $name], [...])` — посмотреть, как локации создаются в существующих тестах `ListingMatcherTest`, и переиспользовать их подход.)

- [ ] **Step 2: Прогнать — FAIL.**

- [ ] **Step 3: Реализовать в `Listing`:**

```php
public function publicationFields(): array
{
    return $this->kind->publicationFields();
}

public static function missingPublicationFields(ListingKind $kind, array $values): array
{
    return array_values(array_filter(
        $kind->publicationFields(),
        fn (string $label, string $field): bool => is_bool($values[$field] ?? null)
            ? false
            : blank($values[$field] ?? null),
        ARRAY_FILTER_USE_BOTH,
    ));
}

public function missingForPublication(): array
{
    $missing = self::missingPublicationFields($this->kind, $this->only(array_keys($this->publicationFields())));

    if ($this->kind->requiresDocument() && $this->documents()->doesntExist()) {
        $missing[] = 'фото документа';
    }

    return $missing;
}
```

Тест-замок «требования к публикации в админке те же, что у веб-формы» временно сузить до rental (полный per-kind вариант вернёт Task 13):

```php
test('требования к публикации аренды те же, что у веб-формы поставщика', function () {
    $webFormRequired = collect((new UpdateSupplierListingRequest)->rules())
        ->filter(fn (array $rules): bool => in_array('required', $rules, true))
        ->keys()->sort()->values()->all();

    expect(collect(array_keys(ListingKind::Rental->publicationFields()))->sort()->values()->all())
        ->toBe($webFormRequired);
});
```

- [ ] **Step 4: Найти и починить всех потребителей `PUBLICATION_FIELDS`.** `grep -rn "PUBLICATION_FIELDS" app tests` — `ListingForm.php` заменить на `Listing::missingPublicationFields(ListingKind::fromNode($get('kind')), ...)` (косметически; полноценная перестройка формы — Task 12), других не оставлять.

- [ ] **Step 5: Прогнать** `make test test_args="--compact --filter=ListingLifecycleTest"` — PASS; широкий прогон `make test test_args="--compact"` — без новых падений.

- [ ] **Step 6: Pint + коммит** `git commit -m "Гейт публикации выводится из вида; у водителя обязателен документ"`.

---

### Task 3: `ListingExtractionAgent` — схема и промпт по виду

**Files:**
- Modify: `app/Ai/Agents/ListingExtractionAgent.php`
- Modify: `app/Services/Ai/SupplierListingCollector.php` (только строка `new ListingExtractionAgent(...)` — передать kind; остальное в Task 4)
- Test: `tests/Feature/AiAgentStrictSchemaTest.php` (проверит новые схемы сам), `tests/Feature/SupplierListingCollectorTest.php` (новые тесты схемы)

**Interfaces:**
- Consumes: `ListingKind` (Task 1).
- Produces: `new ListingExtractionAgent(ListingKind $kind, array $categories = [], array $brands = [])` — **kind первым аргументом**, чтобы существующие позиционные вызовы с массивами упали явно, а не съехали в чужой слот (риск из спеки удаления type). Ключи схемы по видам:
  - общие: `title`, `description`, `location`, `location_detail`, `clarifying_question`, `summary`, `user_intent`;
  - rental: + `category` (enum справочника), `brand` (enum), `price`;
  - repair: + `person_name`, `services`, `repair_place` (enum `own_service|travels|both`), `price` (цена диагностики);
  - driver: + `person_name`, `machine_categories` (**array** items enum справочника категорий), `licence_type` (enum `driver_licence|tractor_operator|other`), `experience_years` (integer), `travels_to_other_cities` (boolean).
  - Все ключи `->nullable()->required()` (кроме `user_intent`, как сейчас). Ключи, чуждые виду, в схему НЕ входят.

**Steps:**

- [ ] **Step 1: Падающий тест** (в `SupplierListingCollectorTest.php`; смотри хелперы в шапке файла — `fullExtraction()` придётся параметризовать видом или добавить `repairExtraction()`/`driverExtraction()`):

```php
test('схема извлечения собирается из вида', function () {
    $schema = app(\Illuminate\Contracts\JsonSchema\JsonSchema::class);

    $rental = array_keys((new ListingExtractionAgent(ListingKind::Rental, ['Автокран'], ['Hitachi']))->schema($schema));
    $repair = array_keys((new ListingExtractionAgent(ListingKind::Repair))->schema($schema));
    $driver = array_keys((new ListingExtractionAgent(ListingKind::Driver, ['Экскаватор']))->schema($schema));

    expect($rental)->toContain('category', 'brand', 'price')->not->toContain('services', 'licence_type')
        ->and($repair)->toContain('person_name', 'services', 'repair_place', 'price')->not->toContain('category', 'brand')
        ->and($driver)->toContain('person_name', 'machine_categories', 'licence_type', 'experience_years', 'travels_to_other_cities')
        ->not->toContain('price', 'brand');
});
```

(Как получить инстанс `JsonSchema` в тесте — посмотреть в `tests/Feature/AiAgentStrictSchemaTest.php`, там уже есть рабочий способ; скопировать его.)

- [ ] **Step 2: Прогнать — FAIL** (старый конструктор).

- [ ] **Step 3: Реализовать.** Конструктор: `public function __construct(private readonly ListingKind $kind, private readonly array $categories = [], private readonly array $brands = []) {}`. `schema()` — общая часть + `match ($this->kind)` добавка:

```php
$fields = [
    'title' => $schema->string()->nullable()->required(),
    'description' => $schema->string()->nullable()->required(),
    'location' => $schema->string()->nullable()->required(),
    'location_detail' => $schema->string()->nullable()->required(),
    'clarifying_question' => $schema->string()->nullable()->required(),
    'summary' => $schema->string()->nullable()->required(),
    'user_intent' => $schema->string()->enum(UserIntent::values())->required(),
];

$fields += match ($this->kind) {
    ListingKind::Rental => [
        'category' => ($this->categories === [] ? $schema->string()
            : $schema->string()->enum($this->categories))->nullable()->required(),
        'brand' => ($this->brands === [] ? $schema->string()
            : $schema->string()->enum($this->brands))->nullable()->required(),
        'price' => $schema->string()->nullable()->required(),
    ],
    ListingKind::Repair => [
        'person_name' => $schema->string()->nullable()->required(),
        'services' => $schema->string()->nullable()->required(),
        'repair_place' => $schema->string()->enum(array_column(RepairPlace::cases(), 'value'))->nullable()->required(),
        'price' => $schema->string()->nullable()->required(),
    ],
    ListingKind::Driver => [
        'person_name' => $schema->string()->nullable()->required(),
        'machine_categories' => ($this->categories === [] ? $schema->array()->items($schema->string())
            : $schema->array()->items($schema->string()->enum($this->categories)))->nullable()->required(),
        'licence_type' => $schema->string()->enum(array_column(LicenceType::cases(), 'value'))->nullable()->required(),
        'experience_years' => $schema->integer()->nullable()->required(),
        'travels_to_other_cities' => $schema->boolean()->nullable()->required(),
    ],
};

return $fields;
```

`instructions()` — та же структура, что сейчас: общая шапка («Ты — оператор сервиса…», правила про «не выдумывай», контекст последнего сообщения бота, user_intent) остаётся общей, блок «Поля:» собирается `match ($this->kind)`. Для repair: services — «какие работы выполняет мастер, его же словами, перечислением», repair_place — «строго одно из own_service/travels/both, только если мастер сказал, где работает», price — «цена за диагностику, как назвал мастер». Для driver: machine_categories — «категории техники СТРОГО из списка, можно несколько», experience_years — «стаж в годах числом, не выдумывай», travels_to_other_cities — «true/false только если сказал явно, иначе null». Шапку для repair/driver переформулировать: «Ты — оператор сервиса по спецтехнике. Из сообщений мастера по ремонту…» / «…водителя спецтехники…».

В `SupplierListingCollector::extract()` строку 767 поправить на `new ListingExtractionAgent(ListingKind::Rental, $categories->pluck('name')->all(), $brands->pluck('name')->all())` — временно захардкоженный Rental, Task 4 заменит на вид из состояния.

- [ ] **Step 4: Прогнать** `make test test_args="--compact --filter=AiAgentStrictSchemaTest"` и `--filter=SupplierListingCollectorTest` — PASS.

- [ ] **Step 5: Pint + коммит** `git commit -m "Извлекающий агент собирает схему и промпт из вида объявления"`.

---

### Task 4: Коллектор — вид из узла, обязательные поля и лимит из вида

**Files:**
- Modify: `app/Services/Ai/SupplierListingCollector.php`
- Test: `tests/Feature/SupplierListingCollectorTest.php`

**Interfaces:**
- Consumes: `ListingKind` (Task 1), `ListingExtractionAgent(kind, ...)` (Task 3).
- Produces: коллектор читает `$node['kind']` через `ListingKind::fromNode()`, кладёт в `state['kind']`, дальше все проверки — от вида. Хелпер состояния: `private function kind(array $state): ListingKind`. Task 5–7 надстраиваются над этим.

**Steps:**

- [ ] **Step 1: Падающие тесты:**

```php
test('вид узла попадает в состояние и в черновик, приветствие — своё', function () {
    $session = collectorSession();
    fakeCollectorMessenger()->shouldReceive('sendText')->once()
        ->withArgs(fn ($to, string $text) => str_contains($text, 'На какой технике'));

    app(SupplierListingCollector::class)->start($session, ['type' => 'ai', 'task' => 'collect_listing', 'kind' => 'driver']);

    expect($session->fresh()->state['kind'])->toBe('driver');
});

test('у ремонта сбор завершается без цены и категории', function () {
    ListingExtractionAgent::fake([repairExtraction()]);   // person_name, services, repair_place, location заполнены; price=null
    $session = collectorSession(['kind' => 'repair']);
    fakeCollectorMessenger()->shouldReceive('sendButtons')->once();   // сводка, не вопрос

    $outcome = app(SupplierListingCollector::class)
        ->resume($session, repairAiNode(), new InboundMessage(text: 'ремонтирую гидравлику, выезжаю, Алматы'));

    expect($outcome)->toBe(AiOutcome::InProgress)
        ->and($session->fresh()->state['phase'])->toBe('confirming');
});

test('лимит уточнений у водителя — шесть', function () {
    ListingExtractionAgent::fake([driverExtraction(['person_name' => null])]);
    $session = collectorSession(['kind' => 'driver', 'attempts' => 5]);
    fakeCollectorMessenger()->shouldReceive('sendText')->once();      // ещё вопрос, не веб-форма

    app(SupplierListingCollector::class)->resume($session, driverAiNode(), new InboundMessage(text: 'стаж 8 лет'));

    expect($session->fresh()->state['attempts'])->toBe(6);
});
```

Хелперы добавить рядом с существующими: `repairAiNode()`/`driverAiNode()` (как `supplierAiNode()` + `'kind'`), `repairExtraction()`/`driverExtraction()` (как `fullExtraction()`, но с ключами вида).

- [ ] **Step 2: Прогнать — FAIL.**

- [ ] **Step 3: Реализовать:**
  - `start()`: `state['kind'] = ListingKind::fromNode($node['kind'] ?? null)->value;` (в начало массива состояния); приветствие: `trim((string)($node['text'] ?? '')) ?: $kind->greeting()`; `ensureDraft()` создаёт черновик с `'kind' => $state['kind']`;
  - `normalizeState()`: default `'kind' => ListingKind::Rental->value` (плюс новые ключи Task 5–6: `'button_prompts' => []`, `'awaiting_document' => false` — добавить сразу, чтобы не трогать метод трижды);
  - `private function kind(array $state): ListingKind { return ListingKind::fromNode($state['kind'] ?? null); }`
  - `missingFields()`: сигнатура `missingFields(array $fields, ListingKind $kind)`, булево-осознанно:

```php
private function missingFields(array $fields, ListingKind $kind): array
{
    return array_values(array_filter(
        $kind->collectorRequiredFields(),
        function (string $field) use ($fields): bool {
            $value = $fields[$field] ?? null;

            // false is an answer («не готов выезжать»), [] is not.
            return is_bool($value) ? false : blank($value);
        },
    ));
}
```

  - `advance()`: `self::MAX_CLARIFICATIONS` → `$this->kind($state)->maxClarifications()`; константу удалить;
  - `extract()`: агент строится от вида; для rental — категории+марки как сейчас; для repair — без справочников (`new ListingExtractionAgent($kind)`); для driver — категории, без марок. `canonicalCategory`/`canonicalBrand` вызывать только у rental; для driver добавить `canonicalMachineCategories(mixed $names, Collection $categories): array` (тот же safety-net по каждому имени, возвращает `list<string>` канонических имён);
  - `listingAttributes()`: собирать из вида —

```php
private function listingAttributes(array $state): array
{
    $fields = $state['fields'];
    $kind = $this->kind($state);
    $title = WhatsappText::templateParameter((string) ($fields['title'] ?? ''));

    $common = [
        'kind' => $kind,
        'title' => $title === '' ? null : Str::limit($title, 255, ''),
        'description' => $fields['description'] ?? null,
        'location_id' => $fields['location_id'] ?? null,
        'location_detail' => $fields['location_detail'] ?? null,
    ];

    return $common + match ($kind) {
        ListingKind::Rental => [
            'category_id' => filled($fields['category'] ?? null)
                ? Category::query()->where('name', $fields['category'])->value('id') : null,
            'brand_id' => filled($fields['brand'] ?? null)
                ? Brand::query()->where('name', $fields['brand'])->value('id') : null,
            'price' => $fields['price'] ?? null,
        ],
        ListingKind::Repair => [
            'person_name' => $fields['person_name'] ?? null,
            'services' => $fields['services'] ?? null,
            'repair_place' => $fields['repair_place'] ?? null,
            'price' => $fields['price'] ?? null,
        ],
        ListingKind::Driver => [
            'person_name' => $fields['person_name'] ?? null,
            'licence_type' => $fields['licence_type'] ?? null,
            'experience_years' => $fields['experience_years'] ?? null,
            'travels_to_other_cities' => $fields['travels_to_other_cities'] ?? null,
        ],
    };
}
```

  После `update()` черновика у водителя — синк техники: `$draft->machineCategories()->sync(Category::query()->whereIn('name', (array) ($state['fields']['machine_categories'] ?? []))->pluck('id'));` (в `advance()` и `handOffToWebForm()`, сразу после `$draft->update(...)`);
  - `clarificationQuestion()`: карту статических вопросов брать из `$kind->fallbackQuestions()` вместо локального массива.

- [ ] **Step 4: Прогнать** `make test test_args="--compact --filter=SupplierListingCollectorTest"` — PASS все, включая старые (rental не изменился).

- [ ] **Step 5: Pint + коммит** `git commit -m "Коллектор собирает анкету вида: поля, лимит и вопросы — из ListingKind"`.

---

### Task 5: Кнопочные дозапросы перечислимых полей

**Files:**
- Modify: `app/Services/Ai/SupplierListingCollector.php`
- Test: `tests/Feature/SupplierListingCollectorTest.php`

**Interfaces:**
- Consumes: `ListingKind::buttonFields()` (Task 1), state-ключ `button_prompts` (Task 4).
- Produces: кнопки с id `kind_choice:{field}:{value}`; фаза `choosing` + state-ключ `button_field`. Ответ кнопкой пишет значение в `state['fields'][$field]` и НЕ пересобирает извлечение; любой другой ответ уходит в обычный `handleCollecting`.

**Steps:**

- [ ] **Step 1: Падающие тесты:**

```php
test('перечислимое поле добирается кнопками и не тратит попытку', function () {
    ListingExtractionAgent::fake([repairExtraction(['repair_place' => null])]);
    $session = collectorSession(['kind' => 'repair']);
    fakeCollectorMessenger()->shouldReceive('sendButtons')->once()
        ->withArgs(fn ($to, string $text, array $buttons) => str_contains($text, 'Где вы выполняете ремонт')
            && count($buttons) === 3 && $buttons[0]['id'] === 'kind_choice:repair_place:own_service');

    app(SupplierListingCollector::class)->resume($session, repairAiNode(), new InboundMessage(text: 'чиню гидравлику, Алматы, я Иван'));

    $state = $session->fresh()->state;
    expect($state['attempts'])->toBe(0)
        ->and($state['phase'])->toBe('choosing')
        ->and($state['button_prompts']['repair_place'])->toBe(1);
});

test('нажатие кнопки заполняет поле и ведёт дальше без вызова модели', function () {
    $session = collectorSession(['kind' => 'repair', 'phase' => 'choosing', 'button_field' => 'repair_place',
        'fields' => repairExtraction(['repair_place' => null]), 'button_prompts' => ['repair_place' => 1]]);
    fakeCollectorMessenger()->shouldReceive('sendButtons')->once();   // сводка

    app(SupplierListingCollector::class)->resume($session, repairAiNode(),
        new InboundMessage(replyId: 'kind_choice:repair_place:both', text: 'И так, и так'));

    expect($session->fresh()->state['fields']['repair_place'])->toBe('both')
        ->and($session->fresh()->state['phase'])->toBe('confirming');
});

test('кнопочный вопрос уходит максимум дважды, потом обычный путь недостающего поля', function () {
    ListingExtractionAgent::fake([repairExtraction(['repair_place' => null])]);
    $session = collectorSession(['kind' => 'repair', 'button_prompts' => ['repair_place' => 2]]);
    fakeCollectorMessenger()->shouldReceive('sendText')->once();      // текстовый вопрос, тратит попытку

    app(SupplierListingCollector::class)->resume($session, repairAiNode(), new InboundMessage(text: 'ещё делаю сварку'));

    expect($session->fresh()->state['attempts'])->toBe(1);
});
```

Для водителя дописать зеркальный тест «Да/Нет» → `travels_to_other_cities === true/false` (маппинг `yes/no` → bool).

- [ ] **Step 2: Прогнать — FAIL.**

- [ ] **Step 3: Реализовать:**
  - константа `private const int MAX_BUTTON_PROMPTS = 2;` и `public const string KIND_CHOICE_PREFIX = 'kind_choice:';`
  - в `advance()` — между веткой location-кандидатов и проверкой лимита:

```php
// An enumerated field with few fixed options is asked with buttons, not
// with a text question: the press costs nothing and cannot misspell.
foreach ($this->kind($state)->buttonFields() as $field => $prompt) {
    if (! in_array($field, $missing, true)) {
        continue;
    }

    if (($state['button_prompts'][$field] ?? 0) >= self::MAX_BUTTON_PROMPTS) {
        continue;   // spent: the field walks the ordinary clarification path
    }

    $state['button_prompts'][$field] = ($state['button_prompts'][$field] ?? 0) + 1;
    $state['phase'] = 'choosing';
    $state['button_field'] = $field;
    $this->persist($session, $state);
    $this->sendButtonPrompt($session, $field, $prompt);

    return AiOutcome::InProgress;
}
```

  - `sendButtonPrompt()`: `$this->messenger->sendButtons($session->contact, $prompt['question'], collect($prompt['options'])->map(fn ($title, $value) => ['id' => self::KIND_CHOICE_PREFIX.$field.':'.$value, 'title' => $title])->values()->all());`
  - в `resume()` — новая фаза перед `collecting`: `if ($state['phase'] === 'choosing') { return $this->handleChoosing($session, $state, $message, $node); }`
  - `handleChoosing()`: если `replyId` начинается с префикса и поле совпадает — распарсить значение (`yes`/`no` → `true`/`false` для булевых полей, прочие — как есть), записать в `state['fields'][$field]`, `state['phase'] = 'collecting'`, `unset($state['button_field'])` и `return $this->advance($session, $state);` также принять точное совпадение текста с заголовком кнопки (сценарная конвенция, как `matchLocationChoice`). Иначе — `return $this->handleCollecting(...)` (обычное дополнение);
  - `repeatCurrentStep()`: для фазы `choosing` повторно слать кнопки поля (вопрос про сервис не должен ронять кнопочный шаг);
  - `currentBotMessageSummary()`: для `choosing` вернуть `'задал вопрос с кнопками: «'.$question.'»'` — короткий ответ текстом читается против него;
  - **важно**: `handleCollecting` после успешного извлечения перетирает `state['fields']` целиком — кнопочное значение исчезнет при следующем сообщении. Как у location-pick: хранить `state['button_answers'][$field] = $value` при нажатии и в `extract()`-результат реаплаить поверх: `foreach (($state['button_answers'] ?? []) as $f => $v) { $fields[$f] = $fields[$f] ?? $v; }` (модельное значение, если оно появилось из слов, выигрывает — человек мог передумать словами).

- [ ] **Step 4: Прогнать** — PASS (все, включая rental-набор: у rental `buttonFields()` пуст, ветка не срабатывает).

- [ ] **Step 5: Pint + коммит** `git commit -m "Перечислимые поля вида добираются кнопками, не тратя попыток"`.

---

### Task 6: Документ водителя — запрос, приём, блокировка отправки

**Files:**
- Modify: `app/Services/Ai/SupplierListingCollector.php`
- Test: `tests/Feature/SupplierListingCollectorTest.php`

**Interfaces:**
- Consumes: `ListingKind::requiresDocument()`, `Listing::documents()`, `ListingMediaType::Document` (Task 1).
- Produces: state-ключ `awaiting_document` (bool). Правило приёма: пока у черновика водителя нет документа и `awaiting_document === true`, входящая фотография сохраняется как `Document` на диск `local` и НЕ уходит в фото-вложения AI-вызова.

**Steps:**

- [ ] **Step 1: Падающие тесты:**

```php
test('водителю со всеми полями, но без документа, бот шлёт просьбу о фото, а не сводку с отправкой', function () {
    ListingExtractionAgent::fake([driverExtraction()]);   // всё заполнено
    $session = collectorSession(['kind' => 'driver']);
    fakeCollectorMessenger()->shouldReceive('sendButtons')->once()
        ->withArgs(fn ($to, string $text, array $buttons) => str_contains($text, 'фото удостоверения')
            && count($buttons) === 1 && $buttons[0]['id'] === SupplierListingCollector::BUTTON_EDIT);

    app(SupplierListingCollector::class)->resume($session, driverAiNode(), new InboundMessage(text: 'Иван, экскаватор, 8 лет, Алматы, выезжаю'));

    expect($session->fresh()->state['awaiting_document'])->toBeTrue()
        ->and($session->fresh()->state['attempts'])->toBe(0);   // просьба бесплатна
});

test('фотография в ответ на просьбу о документе становится непубличным документом', function () {
    Storage::fake('local');
    fakeMediaDownload();   // посмотреть, как фото-скачивание фейкается в существующих тестах интейка, и переиспользовать
    ListingExtractionAgent::fake([driverExtraction()]);
    $session = collectorSession(['kind' => 'driver', 'awaiting_document' => true,
        'fields' => driverExtraction(), 'draft_id' => ($draft = driverDraft())->id]);
    fakeCollectorMessenger()->shouldReceive('sendButtons')->once()
        ->withArgs(fn ($to, $text, array $buttons) => count($buttons) === 2);   // полная сводка: документ есть

    app(SupplierListingCollector::class)->resume($session, driverAiNode(),
        new InboundMessage(mediaId: 'wamid-doc', mediaType: ListingMediaType::Photo));

    expect($draft->fresh()->documents()->count())->toBe(1)
        ->and($draft->fresh()->photos()->count())->toBe(0)
        ->and($draft->fresh()->documents()->first()->disk)->toBe('local');
});
```

(Хелперы `driverDraft()`, `fakeMediaDownload()` — собрать по образцу существующих фото-тестов интейка в этом же файле.)

- [ ] **Step 2: Прогнать — FAIL.**

- [ ] **Step 3: Реализовать:**
  - в `advance()`, ветка `missing === []`, перед переходом в `confirming`:

```php
$kind = $this->kind($state);
$draft = $this->ensureDraft($session, $state);
$draft->update($this->listingAttributes($state));

if ($kind->requiresDocument() && $draft->documents()->doesntExist()) {
    $state['phase'] = 'confirming';
    $state['awaiting_document'] = true;
    $this->persist($session, $state);
    $this->sendConfirmation($session, $state);   // сводка без кнопки отправки, см. ниже

    return AiOutcome::InProgress;
}

$state['awaiting_document'] = false;
```

  - `sendConfirmation()`: когда `awaiting_document` — вместо строки про фото и кнопки «Да, отправить»:

```php
$body = implode("\n", array_filter([
    $text,
    'Остался обязательный шаг: пришлите фото удостоверения — без него объявление не выйдет. '
        .'Снимок увидит только наш оператор, в объявлении он не показывается.',
]));

$this->messenger->sendButtons($session->contact, $body, [
    ['id' => self::BUTTON_EDIT, 'title' => self::BUTTON_EDIT_TITLE],
]);
```

  - `intakeMedia()`: в фото-ветке, до сохранения как Photo:

```php
$draft = $this->ensureDraft($session, $state);

// The bot just asked for the licence document, so the next photo IS the
// document: stored on the non-public disk, never rendered to customers
// and never attached to extraction calls.
if (($state['awaiting_document'] ?? false) && $draft->documents()->doesntExist()) {
    $path = "listings/{$draft->id}/documents/".uniqid('', true).'.jpg';
    Storage::disk('local')->put($path, $download['contents']);

    ListingMedia::create([
        'listing_id' => $draft->id, 'type' => ListingMediaType::Document,
        'disk' => 'local', 'path' => $path,
    ]);

    $state['awaiting_document'] = false;

    return true;
}
```

  - `handleConfirmation()`: нажатие `BUTTON_SUBMIT` при `requiresDocument()` и отсутствующем документе — игнорировать кнопку и повторить сводку-просьбу (кнопки отправки в сообщении не было, но текст «Да, отправить» руками набрать можно); после приёма документа обычный путь (`handleCollecting` → `advance`) сам пришлёт полную сводку;
  - `currentBotMessageSummary()`: для `confirming`+`awaiting_document` — «показал сводку и попросил прислать фото удостоверения»;
  - `photoAttachments()`: уже фильтрует `photos()` — документ в AI-вызов не попадает (проверить, теста достаточно);
  - `handOffToWebForm()`: без изменений — черновик без документа спокойно уходит в веб-форму.

- [ ] **Step 4: Прогнать** `--filter=SupplierListingCollectorTest` — PASS.

- [ ] **Step 5: Pint + коммит** `git commit -m "Документ водителя: обязательный непубличный снимок блокирует отправку из чата"`.

---

### Task 7: Сводка подтверждения — по виду

**Files:**
- Modify: `app/Services/Ai/SupplierListingCollector.php` (`buildSummary`)
- Test: `tests/Feature/SupplierListingCollectorTest.php`

**Interfaces:**
- Consumes: state вида (Task 4). Модельная `summary` из извлечения остаётся приоритетной; `buildSummary()` — фолбэк.

**Steps:**

- [ ] **Step 1: Падающий тест:** сводка водителя без модельной `summary` содержит имя, технику, стаж и готовность выезжать; сводка ремонта — имя, услуги, «с выездом», цену диагностики при наличии.

```php
test('фолбэк-сводка собирается из полей вида', function () {
    $session = collectorSession(['kind' => 'driver', 'phase' => 'confirming',
        'fields' => driverExtraction(['summary' => null])]);
    fakeCollectorMessenger()->shouldReceive('sendText')->once();   // ответ на вопрос про сервис
    fakeCollectorMessenger()->shouldReceive('sendButtons')->once()
        ->withArgs(fn ($to, string $text) => str_contains($text, 'Иван') && str_contains($text, 'Стаж 8 лет'));
    // повтор сводки после вопроса про сервис — удобный способ дернуть sendConfirmation
    ListingExtractionAgent::fake([driverExtraction(['summary' => null, 'user_intent' => 'service_question'])]);

    app(SupplierListingCollector::class)->resume($session, driverAiNode(), new InboundMessage(text: 'это платно?'));
});
```

- [ ] **Step 2: Прогнать — FAIL.**

- [ ] **Step 3: Реализовать** `buildSummary()`:

```php
private function buildSummary(array $fields, ListingKind $kind): string
{
    return match ($kind) {
        ListingKind::Rental => collect([
            collect([$fields['category'] ?? null, $fields['brand'] ?? null])->filter()->implode(' '),
            $fields['location'] ?? null, $fields['price'] ?? null,
        ])->filter()->implode(', '),
        ListingKind::Repair => collect([
            $fields['person_name'] ?? null,
            $fields['services'] ?? null,
            filled($fields['repair_place'] ?? null) ? RepairPlace::from($fields['repair_place'])->label() : null,
            $fields['location'] ?? null,
            filled($fields['price'] ?? null) ? 'диагностика '.$fields['price'] : null,
        ])->filter()->implode(', '),
        ListingKind::Driver => collect([
            $fields['person_name'] ?? null,
            implode(', ', (array) ($fields['machine_categories'] ?? [])) ?: null,
            filled($fields['experience_years'] ?? null) ? 'Стаж '.$fields['experience_years'].' лет' : null,
            filled($fields['licence_type'] ?? null) ? LicenceType::from($fields['licence_type'])->label() : null,
            $fields['location'] ?? null,
            ($fields['travels_to_other_cities'] ?? null) === true ? 'готов выезжать в другие города' : null,
        ])->filter()->implode(', '),
    };
}
```

- [ ] **Step 4: Прогнать, Pint, коммит** `git commit -m "Сводка подтверждения собирается по виду объявления"`.

---

### Task 8: Сценарий — настройка вида у AI-блока и меню-список на 6 пунктов

**Files:**
- Modify: `app/Filament/Pages/BotScenarioEditor.php` (whitelist ~строка 342)
- Modify: `resources/views/filament/pages/bot-scenario-editor.blade.php` (панель AI-узла ~196, дефолты addNode ~966)
- Modify: `app/Services/Bot/ScenarioDefinition.php` (`nodeFingerprint`, ~194)
- Modify: `app/Console/Commands/InstallDefaultBotScenario.php`
- Test: `tests/Feature/BotScenarioEditorTest.php` (или где живут тесты cleanDefinition — найти `grep -rl "cleanDefinition\|draft_definition" tests/Feature`), `tests/Feature/InstallDefaultBotScenarioTest.php` (аналогично найти)

**Interfaces:**
- Consumes: `ListingKind::fromNode()` (Task 1); коллектор и поиск читают `$node['kind']` (Task 4, Task 10).
- Produces: AI-узел несёт ключ `kind`; отпечаток узла включает вид (смена вида — мягкий сброс сессий на блоке, как смена задачи); типовой главный сценарий — список из 6 пунктов.

**Steps:**

- [ ] **Step 1: Падающие тесты:**

```php
test('ключ kind у AI-узла переживает сохранение схемы и попадает в отпечаток', function () {
    // по образцу существующего теста на сохранение task в том же файле
    $definition = ['nodes' => [['id' => 'a', 'type' => 'ai', 'task' => 'collect_listing', 'kind' => 'driver', 'x' => 0, 'y' => 0]], 'edges' => []];
    // ...сохранить через страницу редактора, перечитать draft_definition...
    expect($saved['nodes'][0]['kind'])->toBe('driver');

    $withKind = ScenarioDefinition::fromArray($definition);
    $withoutKind = ScenarioDefinition::fromArray(['nodes' => [['id' => 'a', 'type' => 'ai', 'task' => 'collect_listing', 'x' => 0, 'y' => 0]], 'edges' => []]);
    expect($withKind->nodeFingerprint('a'))->not->toBe($withoutKind->nodeFingerprint('a'));
});

test('типовой главный сценарий — список из шести пунктов с видами на AI-узлах', function () {
    $this->artisan('bot:install-default-scenario --force')->assertSuccessful();

    $definition = BotScenario::main()->publishedDefinition();   // посмотреть точный API в модели
    $menu = collect($definition['nodes'])->firstWhere('id', 'main_menu');

    expect($menu['type'])->toBe('list')
        ->and(count($menu['options']))->toBe(6);

    $driverCollect = collect($definition['nodes'])->firstWhere('id', 'collect_driver');
    expect($driverCollect['task'])->toBe('collect_listing')->and($driverCollect['kind'])->toBe('driver');
});
```

(Точные способы «сохранить через редактор» и «прочитать published definition» взять из существующих тестов этих файлов — они есть, найти по grep.)

- [ ] **Step 2: Прогнать — FAIL.**

- [ ] **Step 3: Реализовать:**
  - `BotScenarioEditor::cleanDefinition()` — рядом с task: `$clean['kind'] = ListingKind::fromNode($node['kind'] ?? null)->value;`
  - blade редактора: под селектом задачи — селект вида (только для AI-узлов; список опций отдать через `editorConfig()`, как conditions/actions, НЕ хардкодить в blade):

```html
<label class="prop-label">Вид объявления</label>
<select x-model="selected.kind" @change="touch()">
    <template x-for="kind in config.listingKinds" :key="kind.value">
        <option :value="kind.value" x-text="kind.label"></option>
    </template>
</select>
```

  в `editorConfig()`: `'listingKinds' => collect(ListingKind::cases())->map(fn ($k) => ['value' => $k->value, 'label' => $k->label()])->all(),`; дефолт нового AI-узла в `addNode()`: `kind: 'rental'`;
  - `ScenarioDefinition::nodeFingerprint()` — добавить `kind` в md5-состав рядом с task;
  - `InstallDefaultBotScenario::mainDialogDefinition()` — меню-список и шесть веток:

```php
['id' => 'main_menu', 'type' => 'list', 'x' => 520, 'y' => 240,
    'text' => 'Выберите, что вам нужно.',
    'options' => [
        ['id' => 'rent_out',    'title' => 'Сдаю спецтехнику'],
        ['id' => 'rent_seek',   'title' => 'Ищу спецтехнику'],
        ['id' => 'master',      'title' => 'Я мастер'],
        ['id' => 'master_seek', 'title' => 'Ищу мастера'],
        ['id' => 'driver',      'title' => 'Я водитель'],
        ['id' => 'driver_seek', 'title' => 'Ищу водителя'],
    ]],
['id' => 'collect_rental', 'type' => 'ai', 'task' => 'collect_listing', 'kind' => 'rental', 'x' => 800, 'y' => 40],
['id' => 'collect_repair', 'type' => 'ai', 'task' => 'collect_listing', 'kind' => 'repair', 'x' => 800, 'y' => 160],
['id' => 'collect_driver', 'type' => 'ai', 'task' => 'collect_listing', 'kind' => 'driver', 'x' => 800, 'y' => 280],
['id' => 'search_rental',  'type' => 'ai', 'task' => 'customer_search', 'kind' => 'rental', 'x' => 800, 'y' => 400],
['id' => 'search_repair',  'type' => 'ai', 'task' => 'customer_search', 'kind' => 'repair', 'x' => 800, 'y' => 520],
['id' => 'search_driver',  'type' => 'ai', 'task' => 'customer_search', 'kind' => 'driver', 'x' => 800, 'y' => 640],
```

  Пункт «Мои объявления» из меню не выкидывать — добавить седьмой строкой списка (7 ≤ 10). Каждому AI-узлу — свой `after_*` текст или общий; все `option:*` выходы обязаны быть подключены (валидатор). Заголовки строк ≤ 24 символа — все влезают.
  - Заголовки: у строк списка есть и `description` (72) — можно добавить пояснения («разместить объявление об аренде» и т.п.), необязательно.

- [ ] **Step 4: Прогнать** тесты редактора и инсталлятора + `--filter=ScenarioValidator` — PASS.

- [ ] **Step 5: Pint + коммит** `git commit -m "AI-блок несёт вид объявления; типовое меню — список из шести веток"`.

---

### Task 9: Матчер и эмбеддинги — жёсткий фильтр и текст по виду

**Files:**
- Modify: `app/Services/Ai/ListingMatcher.php`
- Modify: `app/Services/Ai/ListingEmbeddings.php`
- Modify: `app/Models/Listing.php` (`EMBEDDING_SOURCE_FIELDS`)
- Test: `tests/Feature/ListingMatcherTest.php`, `tests/Feature/ListingEmbeddingTest.php`

**Interfaces:**
- Consumes: `ListingKind`, колонки вида (Task 1).
- Produces:

```php
// ListingMatcher: у match()/matchAll() появляются вид и структурные фильтры.
public function match(string $query, ?Location $within = null, ?ListingKind $kind = null, array $filters = []): Collection;
public function matchAll(string $query, ?Location $within = null, ?ListingKind $kind = null, array $filters = []): Collection;
// $filters: ['needs_travel' => true] — для repair это whereIn(repair_place, [travels, both]),
//           для driver — where(travels_to_other_cities, true). null-kind = без фильтра (старое поведение).
```

**Steps:**

- [ ] **Step 1: Падающие тесты** (в тестах матчера вектора сеются фейковой one-hot-схемой — переиспользовать её; `description`/`price` задавать явно):

```php
test('поиск по виду не показывает чужие виды', function () {
    $rental = Listing::factory()->published()->create(['title' => 'Аренда экскаватора', 'description' => 'копаем', 'price' => '10000']);
    $driver = Listing::factory()->driver()->published()->create(['title' => 'Машинист экскаватора', 'person_name' => 'Иван']);

    $found = app(ListingMatcher::class)->matchAll('экскаватор', kind: ListingKind::Driver);

    expect($found->pluck('id'))->toContain($driver->id)->not->toContain($rental->id);
});

test('фильтр выезда жёсткий и свой у каждого вида', function () {
    $stays = Listing::factory()->repair()->published()->create(['repair_place' => RepairPlace::OwnService, 'services' => 'гидравлика']);
    $travels = Listing::factory()->repair()->published()->create(['repair_place' => RepairPlace::Both, 'services' => 'гидравлика']);

    $found = app(ListingMatcher::class)->matchAll('гидравлика', kind: ListingKind::Repair, filters: ['needs_travel' => true]);

    expect($found->pluck('id'))->toContain($travels->id)->not->toContain($stays->id);
});

test('текст эмбеддинга собирается по виду и не содержит стажа и типа удостоверения', function () {
    $driver = Listing::factory()->driver()->create(['person_name' => 'Иван', 'experience_years' => 8]);
    $driver->machineCategories()->sync([categoryNamed('Экскаватор')->id]);

    $text = app(ListingEmbeddings::class)->sourceText($driver->fresh());

    expect($text)->toContain('Иван', 'Экскаватор')->not->toContain('8', 'удостоверение');
});

test('слова полей вида находят объявление по пересечению слов', function () {
    $master = Listing::factory()->repair()->published()->create(['person_name' => 'Сергей', 'services' => 'сварочные работы']);

    expect(app(ListingMatcher::class)->matchAll('сварочные', kind: ListingKind::Repair)->pluck('id'))
        ->toContain($master->id);
});
```

- [ ] **Step 2: Прогнать — FAIL.**

- [ ] **Step 3: Реализовать:**
  - `baseQuery(?Location $within, ?ListingKind $kind, array $filters)`:

```php
return Listing::query()
    ->searchable()
    ->with(['supplier', 'category', 'brand', 'location', 'machineCategories'])
    ->when($kind, fn (Builder $b): Builder => $b->where('kind', $kind))
    ->when($kind === ListingKind::Repair && ($filters['needs_travel'] ?? false),
        fn (Builder $b): Builder => $b->whereIn('repair_place', [RepairPlace::Travels, RepairPlace::Both]))
    ->when($kind === ListingKind::Driver && ($filters['needs_travel'] ?? false),
        fn (Builder $b): Builder => $b->where('travels_to_other_cities', true))
    ->when($within, fn (Builder $builder): Builder => $builder->whereHas(
        'location', fn (Builder $location) => $location->where('path', 'like', $within->path.'%'),
    ));
```

  (`rankByKeywords`/`rankHybrid` пробрасывают новые аргументы.)
  - `score()` — haystack дополнить: `$listing->person_name, $listing->services, $listing->machineCategories->pluck('name')->implode(' ')`;
  - `ListingEmbeddings::sourceText()`:

```php
public function sourceText(Listing $listing): string
{
    $kindLines = match ($listing->kind) {
        ListingKind::Rental => [
            $listing->category === null ? null : 'Категория: '.$listing->category->name,
            $listing->brand === null ? null : 'Марка: '.$listing->brand->name,
        ],
        ListingKind::Repair => [
            blank($listing->person_name) ? null : 'Мастер: '.$listing->person_name,
            blank($listing->services) ? null : 'Услуги: '.$listing->services,
        ],
        ListingKind::Driver => [
            blank($listing->person_name) ? null : 'Водитель: '.$listing->person_name,
            $listing->machineCategories->isEmpty() ? null : 'Техника: '.$listing->machineCategories->pluck('name')->implode(', '),
        ],
    };

    return implode("\n", array_filter([
        blank($listing->title) ? null : 'Название: '.$listing->title,
        ...$kindLines,
        blank($listing->description) ? null : 'Описание: '.$listing->description,
        $listing->locationLine() === null ? null : 'Локация: '.$listing->locationLine(),
    ]));
}
```

  - `Listing::EMBEDDING_SOURCE_FIELDS` += `'kind', 'person_name', 'services'` (изменение техники — через pivot, атрибутного события нет: в местах синка pivot после сохранения (веб-форма Task 13, админка Task 12, коллектор Task 4) диспатчить `GenerateListingEmbedding::dispatch($listing)` явно, если статус Published; в коллекторе черновик не Published — не нужно);
  - обновить `ListingEmbeddingTest`, который прибит к точному составу sourceText (в отчёте — :117-137): переписать на rental + добавить driver/repair-случаи.

- [ ] **Step 4: Прогнать** `--filter=ListingMatcherTest`, `--filter=ListingEmbeddingTest` — PASS.

- [ ] **Step 5: Pint + коммит** `git commit -m "Поиск фильтрует по виду в SQL; текст семантического индекса — из полей вида"`.

---

### Task 10: Поиск заказчика — вид ветки, промпт, строки выдачи, ссылки

**Files:**
- Modify: `app/Ai/Agents/SearchQueryExtractionAgent.php`
- Modify: `app/Services/Ai/CustomerSearchAssistant.php`
- Modify: `app/Services/Ai/CtaLinkBuilder.php` (`catalogUrl` + kind)
- Test: `tests/Feature/CustomerSearchAssistantTest.php`

**Interfaces:**
- Consumes: `ListingKind` (Task 1), `node['kind']` (Task 8), матчер с видом (Task 9).
- Produces:
  - `new SearchQueryExtractionAgent(ListingKind $kind)` — промпт по виду; схема получает ключ `needs_travel` (`boolean|null`, только repair/driver; у rental ключа нет);
  - `CustomerSearchAssistant` кладёт `kind` в state (из `node['kind']` в `start()`; default `rental`), пробрасывает его в `matcher->match(...)`, `matchAll`-подобные вызовы и в каталожные ссылки;
  - `CtaLinkBuilder::catalogUrl(Contact $contact, ?string $query = null, ?Location $location = null, ?ListingKind $kind = null)` — добавляет `kind` в prefill-параметры (не в подпись — каталог валидирует только path+expiry).

**Steps:**

- [ ] **Step 1: Падающие тесты** (файл на ~60 тестов — хелперы поднять из его шапки; фейк агента по образцу существующих):

```php
test('ветка «ищу водителя» ищет только водителей и передаёт вид в каталоге', function () {
    $rental = Listing::factory()->published()->create(['title' => 'Аренда экскаватора', 'description' => 'к', 'price' => '1']);
    $driver = Listing::factory()->driver()->published()->create(['person_name' => 'Иван', 'title' => 'Машинист']);
    SearchQueryExtractionAgent::fake([searchExtraction(['subject' => 'машинист экскаватора', 'location_any' => true])]);

    $session = searchSession();   // существующий хелпер
    fakeSearchMessenger()->shouldReceive('sendList')->once()
        ->withArgs(fn ($to, $text, $button, array $rows) => count($rows) === 1
            && str_contains($rows[0]['id'], (string) $driver->id));
    fakeSearchMessenger()->shouldReceive('sendCtaUrl')->once()
        ->withArgs(fn ($to, $text, $button, string $url) => str_contains($url, 'kind=driver'));

    app(CustomerSearchAssistant::class)->resume($session, driverSearchNode(), new InboundMessage(text: 'нужен машинист экскаватора'));
});

test('строка выдачи мастера — имя и услуги, водителя — имя, техника и стаж', function () {
    $master = Listing::factory()->repair()->published()->create([
        'person_name' => 'Сергей', 'services' => 'гидравлика, двигатели', 'title' => 'Ремонт гидравлики']);

    $row = invade(app(CustomerSearchAssistant::class))->listRow($master);   // либо протестировать через sendList-мок, как в существующих тестах

    expect($row['title'])->toBe('Сергей')
        ->and($row['description'])->toContain('гидравлика');
});

test('сказанный заказчиком выезд превращается в жёсткий фильтр', function () {
    SearchQueryExtractionAgent::fake([searchExtraction(['subject' => 'гидравлика', 'needs_travel' => true, 'location_any' => true])]);
    $stays = Listing::factory()->repair()->published()->create(['repair_place' => RepairPlace::OwnService, 'services' => 'гидравлика']);
    // ...мок sendList: строк ноль или без $stays — и проверить текст пустой выдачи, если ноль
});
```

(`invade()` в проекте может отсутствовать — тогда тестировать через мок `sendList`, как это делают существующие тесты строк.)

- [ ] **Step 2: Прогнать — FAIL.**

- [ ] **Step 3: Реализовать:**
  - `SearchQueryExtractionAgent`: конструктор с `ListingKind`; промпт — общий каркас + `match`: rental как сейчас; repair — «Из сообщений заказчика пойми, какой ремонт спецтехники ему нужен… subject: что сломалось или какая услуга нужна… needs_travel: true, только если заказчик явно сказал, что мастер должен приехать к нему»; driver — «…какой водитель/машинист нужен… needs_travel: true, только если работа в другом городе и водитель должен выезжать». Схема: `needs_travel` — `boolean()->nullable()->required()` у repair/driver;
  - `CustomerSearchAssistant`: `defaultState()` + `'kind' => ListingKind::Rental->value`; `start()` пишет `kind` из узла; хелпер `kind($state)`; `extractRequirements()` строит агента от вида и сохраняет `needs_travel` в requirements; `runSearch()`/`expandSearch()` — `$this->matcher->match($query, $location, $this->kind($state), array_filter(['needs_travel' => $requirements['needs_travel'] ?? null]))` (у rental фильтров нет); `needs_travel` держать в state рядом с `subject`, чтобы уточнения не теряли его;
  - `listRow()`:

```php
protected function listRow(Listing $listing): array
{
    $row = ['id' => self::ROW_ID_PREFIX.$listing->id, 'title' => $this->rowTitle($listing)];

    $description = match ($listing->kind) {
        ListingKind::Rental => implode(' · ', array_filter([$listing->locationLine(), $listing->price])),
        ListingKind::Repair => implode(' · ', array_filter([$listing->services, $listing->locationLine()])),
        ListingKind::Driver => implode(' · ', array_filter([
            $listing->machineCategories->pluck('name')->implode(', ') ?: null,
            $listing->experience_years !== null ? 'стаж '.$listing->experience_years.' л.' : null,
            $listing->locationLine(),
        ])),
    };

    if ($description !== '') {
        $row['description'] = WhatsappText::clamp($description, self::ROW_DESCRIPTION_LIMIT);
    }

    return $row;
}

protected function fullRowTitle(Listing $listing): string
{
    return ($listing->kind !== ListingKind::Rental && filled($listing->person_name) ? $listing->person_name : null)
        ?? $listing->displayName() ?: 'Объявление №'.$listing->id;
}
```

  - `CtaLinkBuilder::catalogUrl()`: `+ 'kind' => $kind?->value` в `$prefill`; все вызовы `catalogUrl(...)` в ассистенте передают вид (rental можно не передавать — параметр опущен, каталог покажет всё, как сегодня; решение: передавать всегда при не-rental);
  - `start()`-приветствие per kind: rental как сейчас; repair — «Опишите, что сломалось и в каком городе…»; driver — «Опишите, какой водитель нужен и в каком городе…» (дефолты, оператор может переписать в узле).

- [ ] **Step 4: Прогнать** `--filter=CustomerSearchAssistantTest` — PASS (старые тесты идут по rental-дефолту).

- [ ] **Step 5: Pint + коммит** `git commit -m "Поиск заказчика: ветка вида, свой промпт, свои строки выдачи, вид в каталожной ссылке"`.

---

### Task 11: Веб-каталог — фильтр вида, карточки, бейдж

**Files:**
- Modify: `app/Http/Controllers/CustomerCatalogController.php` (`filters()`, `paginate()`)
- Modify: `resources/views/customer/catalog.blade.php`
- Modify: `resources/views/customer/listing-show.blade.php`
- Modify: `resources/views/storefront-design-preview.blade.php`
- Test: `tests/Feature/CustomerCatalogTest.php`

**Interfaces:**
- Consumes: колонки вида (Task 1), `catalogUrl(kind:)` (Task 10), матчер (Task 9).
- Produces: параметр `kind` каталога (мусор молча отбрасывается, как остальные фильтры; параметр — НЕ авторизация, просто фильтр).

**Steps:**

- [ ] **Step 1: Падающие тесты:**

```php
test('фильтр вида в каталоге жёсткий, мусорный вид молча отбрасывается', function () {
    $rental = Listing::factory()->published()->create([...]);
    $driver = Listing::factory()->driver()->published()->create([...]);

    $this->get(catalogUrl(['kind' => 'driver']))          // хелпер подписанной ссылки — есть в этом файле
        ->assertSee($driver->person_name)->assertDontSee($rental->title);

    $this->get(catalogUrl(['kind' => 'nonsense']))->assertOk();   // всё, без фильтра
});

test('карточка водителя показывает стаж со слов и бейдж только у проверенного документа', function () {
    $verified = Listing::factory()->driver()->published()->create([
        'person_name' => 'Иван', 'experience_years' => 8, 'document_verified_at' => now()]);
    $unverified = Listing::factory()->driver()->published()->create([
        'person_name' => 'Пётр', 'document_verified_at' => null]);

    $page = $this->get(catalogUrl(['kind' => 'driver']));
    $page->assertSee('Стаж 8 лет (со слов исполнителя)')->assertSee('Документ проверен');
    // и у показа $unverified бейджа нет — проверить на странице объявления
    $this->get(listingShowUrl($unverified))->assertDontSee('Документ проверен');
});

test('фото документа не попадает в галерею и в карточку', function () {
    $driver = Listing::factory()->driver()->published()->create();
    ListingMedia::create(['listing_id' => $driver->id, 'type' => ListingMediaType::Document, 'disk' => 'local', 'path' => 'doc.jpg']);

    $this->get(listingShowUrl($driver))->assertDontSee('doc.jpg');
});
```

- [ ] **Step 2: Прогнать — FAIL** (бейджей/фильтра нет; тест про документ, вероятно, PASS сразу — `photos()` фильтрует по типу; оставить как замок).

- [ ] **Step 3: Реализовать:**
  - `filters()`: `+ 'kind' => ListingKind::tryFrom((string) $request->query('kind'))` (null — без фильтра); `paginate()`: `->when($filters['kind'], fn (Builder $b, ListingKind $kind) => $b->where('kind', $kind))` и проброс `with('machineCategories')`; скрытые поля формы фильтров каталога должны нести `kind` дальше (страница, сортировка);
  - `catalog.blade.php` — мета-строка карточки по виду (вместо безусловной «категория · марка»):

```blade
@if ($listing->kind === \App\Enums\ListingKind::Repair)
    <div class="card-meta">{{ $listing->services }}</div>
    @if ($listing->repair_place) <div class="card-meta">{{ $listing->repair_place->label() }}</div> @endif
@elseif ($listing->kind === \App\Enums\ListingKind::Driver)
    <div class="card-meta">{{ $listing->machineCategories->pluck('name')->implode(', ') }}</div>
    @if ($listing->experience_years !== null)
        <div class="card-meta">Стаж {{ $listing->experience_years }} лет (со слов исполнителя)</div>
    @endif
    @if ($listing->document_verified_at) <div class="card-badge">✅ Документ проверен</div> @endif
@else
    {{-- как сейчас: категория · марка --}}
@endif
```

  Первая строка карточки — `person_name` для repair/driver (над названием). Цена: у repair подписывать «Диагностика: …» при наличии; у driver строки цены нет;
  - `listing-show.blade.php` — тот же набор строк + `travels_to_other_cities === true ? 'Готов выезжать в другие города'` + `licence_type?->label()` у водителя + бейдж; помнить: страница рендерится и модератору для черновика — каждое поле оборачивать в `@if`, пустое не фатально;
  - CSS `card-badge` — в инлайновый `<style>` layout'а (страницы без Vite);
  - `storefront-design-preview.blade.php`: в секции каталога добавить карточку мастера и карточку водителя (мобайл и десктоп), в секцию страницы объявления — вариант водителя с бейджем; CSS скопировать в стилевой блок превью.

- [ ] **Step 4: Прогнать** `--filter=CustomerCatalogTest` — PASS. Открыть превью локально (`/design/storefront` — маршрут в `routes/web.php:18`) и глазами сверить карточки.

- [ ] **Step 5: Pint + коммит** `git commit -m "Каталог: фильтр вида, карточки мастера и водителя, бейдж проверенного документа"`.

---

### Task 12: Админка — форма по виду, документ, галочка проверки

**Files:**
- Modify: `app/Filament/Resources/Listings/Schemas/ListingForm.php`
- Modify: `app/Filament/Resources/Listings/Tables/ListingsTable.php`
- Modify: `app/Filament/Resources/Listings/Pages/CreateListing.php` (preserve-список)
- Modify: `app/Filament/Resources/Listings/ListingResource.php` (global search attrs — добавить `person_name`)
- Test: `tests/Feature/ListingAdminTest.php` (или существующий файл тестов ресурса — найти `grep -rl "ListingForm\|ListingResource" tests/Feature`)

**Interfaces:**
- Consumes: enum'ы и связи (Task 1), гейт (Task 2).
- Produces: поле `kind` (Select, `->live()`, default rental); секции полей вида с `visible(fn (Get $get) => $get('kind') === '...')`; блок документа (просмотр непубличного снимка + Toggle «Документ проверен»); синк `machineCategories` при сохранении; сброс галочки при замене документа.

**Steps:**

- [ ] **Step 1: Падающие тесты** (Livewire-тесты Filament — по образцу существующих в найденном файле):

```php
test('форма показывает поля вида и прячет чужие', function () {
    // livewire(EditListing::class, ['record' => $driver->id])
    //   ->assertSchemaComponentExists('licence_type')
    //   ->assertSchemaComponentDoesNotExist('price') ... по API существующих тестов
});

test('галочка проверки фиксирует оператора и время, снятие — очищает', function () {
    // сохранить форму с document_verified=true → document_verified_at != null, document_verified_by == оператор
});

test('подсказка «чего не хватает» считается по виду', function () {
    // у водителя без документа подсказка содержит «фото документа»
});
```

- [ ] **Step 2: Прогнать — FAIL.**

- [ ] **Step 3: Реализовать:**
  - `ListingForm`: после статуса — `Select::make('kind')->label('Вид')->options(collect(ListingKind::cases())->mapWithKeys(fn ($k) => [$k->value => $k->label()]))->default(ListingKind::Rental->value)->live()->required()`;
  - существующие поля `category_id`, `brand_id`, `price` — `->visible(fn (Get $get) => ...)` по виду (price виден rental и repair, с меткой «Цена за диагностику» у repair через `->label(fn (Get $get) => ...)`), `description` остаётся у всех;
  - новые компоненты: `TextInput::make('person_name')` (repair+driver), `Textarea::make('services')` (repair), `Select::make('repair_place')->options(...)` (repair), `Select::make('machine_categories')->relationship('machineCategories', 'name')->multiple()->preload()` (driver), `Select::make('licence_type')->options(...)` (driver), `TextInput::make('experience_years')->numeric()` (driver), `Toggle::make('travels_to_other_cities')` (driver);
  - блок документа (driver): `ViewField` или `ImageEntry`-подобный вывод снимка через подписанный маршрут не нужен — оператор аутентифицирован: отдать через `Storage::disk('local')->temporaryUrl()` нельзя (local), поэтому простой контроллер `admin/listings/{listing}/document` за `auth`-middleware, отдающий `Storage::disk($media->disk)->response($media->path)` (создать `ListingDocumentController` + маршрут рядом с `ListingPreviewController` в `routes/web.php`); в форме — ссылка/превью на него + `Toggle::make('document_verified')->label('Документ проверен')`;
  - Toggle — виртуальное поле: в Edit-странице `mutateFormDataBeforeFill` (`document_verified = filled(document_verified_at)`) и `mutateFormDataBeforeSave`:

```php
if (($data['document_verified'] ?? false) && blank($this->record->document_verified_at)) {
    $data['document_verified_at'] = now();
    $data['document_verified_by'] = auth()->id();
} elseif (! ($data['document_verified'] ?? false)) {
    $data['document_verified_at'] = null;
    $data['document_verified_by'] = null;
}
unset($data['document_verified']);
```

  - подсказка «чего не хватает» (строки 314-323): `Listing::missingPublicationFields(ListingKind::fromNode($get('kind')), $get-значения)` + «фото документа» у driver без документа;
  - `ListingsTable`: колонка `kind` (badge, label из enum) + фильтр по виду; колонка `person_name` (toggleable);
  - `CreateListing::preserveFormDataWhenCreatingAnother` — добавить `kind`;
  - `ListingResource::getGloballySearchableAttributes()` — добавить `person_name`.
  - **Не переименовывать и не прятать** существующие семь полей у rental — демо-видео ищет их по имени, а его сценарий заполняет rental-объявление.

- [ ] **Step 4: Прогнать** тесты админки + `make demo-video` (только прогон записи сценария; если упал — чинить селекторы, ролик не перезаписывать).

- [ ] **Step 5: Pint + коммит** `git commit -m "Админка: форма и таблица по виду, просмотр документа и галочка проверки"`.

---

### Task 13: Веб-форма поставщика — поля вида, документ, валидация

**Files:**
- Modify: `app/Http/Requests/UpdateSupplierListingRequest.php`
- Modify: `app/Http/Controllers/SupplierListingController.php`
- Modify: `resources/views/supplier/listing-edit.blade.php` (+ read-only `<dl>`)
- Modify: `resources/views/supplier/listings-index.blade.php` (строка карточки по виду)
- Modify: `resources/views/storefront-design-preview.blade.php` (секции формы)
- Modify: `tests/Feature/ListingLifecycleTest.php` (вернуть полный тест-замок)
- Test: `tests/Feature/SupplierPortalTest.php` (или как называется файл тестов веб-формы — найти)

**Interfaces:**
- Consumes: гейт (Task 2), enum'ы (Task 1), эмбеддинг-диспатч после синка pivot (Task 9).
- Produces: `UpdateSupplierListingRequest::rules()` ветвится по `$this->route('listing')->kind`; финальный тест-замок: для КАЖДОГО вида `required`-ключи формы == `array_keys($kind->publicationFields())` минус `title`-исключения нет — title required в форме, как сейчас.

**Steps:**

- [ ] **Step 1: Падающие тесты:**

```php
test('веб-форма водителя требует поля вида и документ, но не цену', function () {
    $draft = Listing::factory()->driver()->create();   // без полей
    $response = $this->put(supplierUpdateUrl($draft), ['title' => 'Машинист']);

    $response->assertSessionHasErrors(['person_name', 'licence_type', 'experience_years', 'location_id', 'travels_to_other_cities', 'machine_categories', 'document'])
        ->assertSessionDoesntHaveErrors(['price', 'category_id', 'description']);
});

test('замена документа сбрасывает галочку проверки', function () {
    Storage::fake('local');
    $draft = Listing::factory()->driver()->create(['document_verified_at' => now(), 'document_verified_by' => User::factory()]);
    ListingMedia::create(['listing_id' => $draft->id, 'type' => ListingMediaType::Document, 'disk' => 'local', 'path' => 'old.jpg']);

    $this->put(supplierUpdateUrl($draft), [...validDriverPayload(), 'document' => UploadedFile::fake()->image('new.jpg')]);

    expect($draft->fresh()->document_verified_at)->toBeNull()
        ->and($draft->fresh()->documents()->count())->toBe(1);   // старый удалён вместе с файлом
});

// финальный замок вместо rental-временного из Task 2:
test('требования к публикации совпадают у модели и веб-формы для каждого вида', function (ListingKind $kind) {
    $listing = Listing::factory()->create(['kind' => $kind]);
    $request = UpdateSupplierListingRequest::create('', 'PUT');
    $request->setRouteResolver(fn () => routeWith('listing', $listing));   // по образцу существующих тестов запросов; иначе — протащить kind явно

    $required = collect($request->rules())
        ->filter(fn (array $rules): bool => in_array('required', $rules, true))
        ->keys()->reject(fn (string $k) => in_array($k, ['document', 'machine_categories'], true))   // связи и файл проверяются отдельно от скалярного гейта
        ->sort()->values()->all();

    expect(collect(array_keys($kind->publicationFields()))->sort()->values()->all())->toBe($required);
})->with(ListingKind::cases());
```

(Если резолвить route в юнит-стиле неудобно — дать `rules()` явный параметр: `public function rules(): array { $kind = $this->route('listing')?->kind ?? ListingKind::Rental; return $this->rulesFor($kind); }` и тестировать `rulesFor()` напрямую. `machine_categories` и `document` — вне скалярного замка: техника — pivot (в `publicationFields()` её нет — она не скалярное поле гейта; сбор требует её через `collectorRequiredFields`, веб-форма — своей required-строкой, модерация видит глазами), документ — в гейте отдельной строкой «фото документа».)

- [ ] **Step 2: Прогнать — FAIL.**

- [ ] **Step 3: Реализовать:**
  - `rules()` → `rulesFor(ListingKind $kind)`: общие `title`/`location_id`/`location_detail`/`photos*`; rental — как сейчас; repair — `person_name`, `services`, `repair_place` (`Rule::enum(RepairPlace::class)`) required, `price`/`description` nullable; driver — `person_name`, `licence_type` (`Rule::enum(LicenceType::class)`), `experience_years` (`integer|min:0|max:80`), `travels_to_other_cities` (`boolean`, required — чекбокс с hidden 0), `machine_categories` (`required|array|min:1`, `machine_categories.*` exists:categories,id) required, `description` nullable, `document` — `required_without:has_document|file|image|mimes:jpg,jpeg,png,webp|max:10240` (has_document — hidden-флаг наличия уже загруженного);
  - `attributes()`/`messages()` — русские метки новых полей;
  - `SupplierListingController::edit()` — отдать в view enum-опции и текущие связи; `update()`:

```php
$listing->fill($request->safe()->except(['photos', 'remove_photos', 'document', 'machine_categories', 'has_document']));

if ($listing->kind === ListingKind::Driver) {
    $listing->machineCategories()->sync($request->validated('machine_categories', []));

    if ($request->hasFile('document')) {
        // Replacing the licence photo voids the operator's verification:
        // it referred to the old shot.
        $listing->documents()->get()->each(function (ListingMedia $media) {
            Storage::disk($media->disk)->delete($media->path);
            $media->delete();
        });
        $path = $request->file('document')->store("listings/{$listing->id}/documents", 'local');
        ListingMedia::create(['listing_id' => $listing->id, 'type' => ListingMediaType::Document, 'disk' => 'local', 'path' => $path]);
        $listing->fill(['document_verified_at' => null, 'document_verified_by' => null]);
    }
}

$listing->save();
```

  (+ существующая логика фото; `submitForModeration()` как раньше; после save у Published — если синкали технику, `GenerateListingEmbedding::dispatch($listing)`; поставщик правит только Draft/Rejected, так что практически не сработает — оставить не нужно, НЕ добавлять);
  - blade формы: секции по `$listing->kind` (сервер знает вид — никакого JS-переключателя; вид в веб-форме НЕ меняется), инпуты в том же инлайн-стиле; для driver — `<select multiple>` не использовать (мобильный UX) — чекбоксы категорий в прокручиваемом блоке; загрузка документа отдельной секцией с пояснением про непубличность; read-only `<dl>` — те же поля по виду;
  - `listings-index.blade.php`: подзаголовок карточки по виду (имя/услуги/техника);
  - превью-blade: секции формы мастера и водителя (mobile), read-only вариант.

- [ ] **Step 4: Прогнать** тесты портала + `--filter=ListingLifecycleTest` (замок) — PASS. Широкий `make test test_args="--compact"` — зелёный.

- [ ] **Step 5: Pint + коммит** `git commit -m "Веб-форма поставщика: анкета вида, загрузка документа, сброс проверки при замене"`.

---

### Task 14: Документация, changelog, деплой-заметки

**Files:**
- Modify: `docs/technical-specification.md` (Модуль 2: лимит уточнений — по виду; Модуль 4: поля по видам; Модуль 5: меню-список и шесть веток; Модуль 6 п.2: лимит по виду)
- Modify: `docs/business-rules.md` (строки 9, 13, 29-35: обязательные поля и лимит — по виду; новое правило документа и галочки; поиск — жёсткий фильтр вида)
- Modify: `docs/modules/ai-assistant.md` (сбор по видам, кнопочные дозапросы, документ, лимиты; поиск по видам, needs_travel)
- Modify: `docs/modules/listings-lifecycle.md` (поля видов, документ-медиа как непубличный класс, галочка проверки и её сброс, 30-дневный цикл — общий для видов)
- Modify: `docs/modules/user-flows.md` (меню-список, потоки мастера и водителя)
- Modify: `docs/modules/whatsapp-integration.md` (веб-форма по видам, карточки каталога, фильтр kind)
- Modify: `docs/modules/bot-constructor.md` (настройка «Вид» AI-блока, вид в отпечатке узла)
- Modify: `docs/changelog.md` (новая запись сверху)

**Steps:**

- [ ] **Step 1: Обновить каждый файл.** Правила: поведение, не реализация — никаких имён классов/колонок; формулировка «лимит уточнений задаётся видом: аренда 3, ремонт 4, водитель 6» заменяет «2–3 попытки» во всех трёх местах (ТЗ, business-rules, ai-assistant) одинаково; правило документа: «фото удостоверения обязательно для публикации анкеты водителя, хранится непублично, видно только оператору; проверка — явная отметка оператора, не следствие публикации; замена снимка снимает отметку»; отметить осознанное исключение из «фотографии ничего не блокируют» со ссылкой на причину.

- [ ] **Step 2: Запись в changelog** — по стилю верхней записи: что изменилось для пользователя (шесть веток меню, анкеты мастера и водителя, бейдж), какие модули затронуты, какие правила изменены (лимит, блокирующий документ, фильтр вида).

- [ ] **Step 3: Деплой-заметки** — в текст changelog-записи или PR-описания:
  - `make artisan artisan_args="migrate --no-interaction"`;
  - `make artisan artisan_args="bot:install-default-scenario --force"` (перезапишет все три типовых сценария; активные AI-сессии получат мягкий сброс в меню);
  - разовая переиндексация эмбеддингов (шаблон текста изменился для всех видов):

```bash
docker exec sala-app-1 php artisan tinker --execute='App\Models\Listing::query()->where("status", "published")->each(fn ($l) => App\Jobs\GenerateListingEmbedding::dispatch($l));'
```

  - локальная копия ТЗ разошлась с Google Docs — синхронизация за владельцем.

- [ ] **Step 4: Финальный полный прогон** `make test test_args="--compact"` — зелёный; `vendor/bin/pint --dirty --format agent`.

- [ ] **Step 5: Коммит** `git commit -m "Доки: виды объявлений, анкеты, документ водителя, лимиты по виду"`.

---

## Self-Review (выполнен при написании)

- **Покрытие спеки:** вид-enum и описание (T1), гейт по виду + документ (T2), схема/промпт агента (T3), сбор с лимитами вида (T4), кнопки (T5), документ в чате (T6), сводка (T7), меню и настройка блока (T8), фильтр поиска + эмбеддинги (T9), ветки поиска + строки + ссылки (T10), каталог + бейдж + превью (T11), админка + проверка документа (T12), веб-форма + сброс галочки + тест-замок (T13), доки + деплой (T14). Рейтинг — вне объёма (в карточке лишь зарезервировано место — T11 ничего для него не делает, и не должен).
- **Типы согласованы:** `ListingKind::fromNode`, `publicationFields`, `collectorRequiredFields`, `maxClarifications`, `requiresDocument`, `buttonFields`, `fallbackQuestions`, `greeting` — объявлены в T1, потребители ссылаются на эти имена; `missingPublicationFields(ListingKind, array)` — новая сигнатура объявлена в T2 и используется в T12; `match(query, within, kind, filters)` — T9, используется в T10/T11.
- **Известные хвосты, оставленные сознательно:** `approve()` не перепроверяет полноту (сегодняшнее поведение, оба пути в очередь модерации проверяют полноту сами); повторный вход в опрос актуальности и заявки не трогаются; `CustomerRequest` без вида (наследует вид листинга).
