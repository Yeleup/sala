# Техника вне справочника в анкете водителя — план реализации

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Водитель, назвавший технику, которой нет в справочнике категорий («Автобус»), больше не получает один и тот же вопрос до исчерпания лимита. Бот честно говорит, что такой техники в списке нет, даёт кнопку «Нет в списке», сохраняет названное словами и отдаёт анкету на модерацию с пометкой оператору. Веб-форма перестаёт требовать категорию, если техника названа словами. Общая страховка: текстовый уточняющий вопрос, три хода подряд не дающий продвижения, больше не задаётся — уходит веб-форма.

**Контекст:** инцидент 2026-09-04, контакт 225 (сессия 17): пять вызовов извлечения, `machine_categories: null` во всех (схема — enum из справочника, «Автобус» невыразим), четыре дословных повтора «На какой технике вы работаете — экскаватор, самосвал, кран?», `attempts = 5` при лимите 6, поставщик ушёл, черновика нет. Продуктовое решение владельца: поле пропускаемое, объявление уходит на модерацию с пометкой; веб-форма не требует категорию при технике словами.

**Architecture:** Новое поле извлечения `unlisted_machinery` (строка, техника словами водителя, если её нет в списке) и новая колонка `listings.unlisted_machinery`. Коллектор при `machine_categories` пустом и `unlisted_machinery` заполненном шлёт честный промпт с кнопкой «Нет в списке» (не тратит попытку, максимум дважды, потом поле закрывается само). Закрытое поле не считается недостающим — анкета идёт к сводке и на модерацию. Детектор холостого хода: набор недостающих полей не изменился на третьем подряд текстовом уточнении — веб-форма. Оператор видит технику словами в форме объявления и таблице. Публичные и кабинетные строки «техника» строятся из одного аксессора `Listing::machineryLine()` — категории + техника словами.

**Tech Stack:** Laravel 13, PHP 8.4, Filament v5, Pest 4, laravel/ai (strict JSON schema).

## Global Constraints

- Тесты гонять ТОЛЬКО через `make test ENV_FILE=<свой env> test_args="--compact --filter=..."`. Никогда `php artisan test` напрямую и никогда через `docker exec` — снесёт dev-базу. У каждой задачи свой `ENV_FILE` (см. задачу) — параллельные прогоны не должны делить тестовую БД.
- Все PHP/composer-команды — только в контейнерах (`make artisan artisan_args="..."`, `docker exec sala-app-1 php ...`); host-PHP отсутствует как опция.
- После правок PHP — pint ТОЛЬКО на своих файлах: `docker exec sala-app-1 vendor/bin/pint --format agent <пути>` (не `--dirty`: параллельные задачи правят другие файлы).
- Каждая задача правит только файлы из своего списка **Files**. Нужное в чужом файле — записать в отчёт, не править.
- Никаких коммитов.
- WhatsApp-лимиты: ≤3 reply-кнопки, подпись кнопки ≤20 символов.
- Никакого фразового хардкодинга: кнопка «Нет в списке» узнаётся по `replyId` и по точному совпадению набранного текста с её подписью (существующая конвенция `matchesButton`); ничего другого по словам не распознаётся. Техника вне справочника приходит от модели типизированным полем `unlisted_machinery`, не грепом транскрипта.
- Тексты сообщений — дословно из копирайт-таблицы ниже; отсебятина — дефект ревью. Бот пишет только по-русски, без эмодзи, на «вы».
- Существующие тесты, завязанные на старое поведение, обновлять — не удалять.
- Документация описывает поведение, не реализацию (без имён классов/колонок/state-ключей).

## Контракты (единый источник для всех задач)

**Поле извлечения (водитель):** `unlisted_machinery` — `string|null`. Промпт: «техника, на которой работает водитель, если её НЕТ в списке категорий — его же словами, одним-двумя словами в именительном падеже («автобус», «водовоз»). Техника из списка сюда не попадает — она идёт в machine_categories. Не названа — null.» В схеме: `$schema->string()->nullable()->required()`. В `clarifiableFields()` НЕ добавляется.

**Колонка:** `listings.unlisted_machinery` — `string(120) nullable`. В `#[Fillable]`. В `Listing::EMBEDDING_SOURCE_FIELDS`. Фабрика `driver()`: `'unlisted_machinery' => null`.

**Аксессор:** `Listing::machineryLine(): ?string` — имена `machineCategories` (в порядке связи) + `unlisted_machinery`, через `, `; `null`, если нечего показать. Единственный источник строки «техника» для витрины, кабинета, матчера и эмбеддингов.

**State коллектора (новые ключи, в `start()` и `normalizeState()`):**
- `unlisted_prompts` (int, 0) — сколько раз ушёл промпт «Нет в списке».
- `machinery_skipped` (bool, false) — поле техники закрыто: кнопка нажата или промпты исчерпаны.
- `unlisted_machinery` (string|null) — запомненная техника словами (переживает пересборку полей, как `button_answers`: значение из слов на текущем ходу побеждает, при null подставляется запомненное).
- `stalled_missing` (list<string>|null), `stalled_turns` (int, 0) — детектор холостого хода.

**Константы коллектора:** `MAX_UNLISTED_PROMPTS = 2`; `MAX_STALLED_TURNS = 2`; `BUTTON_MACHINERY_UNLISTED = 'collect_machinery_unlisted'`; `BUTTON_MACHINERY_UNLISTED_TITLE = 'Нет в списке'`.

**Правила коллектора:**
1. В `extract()` для водителя после `canonicalMachineCategories`: если `unlisted_machinery` дословно (без регистра, trim) совпадает с категорией справочника — перенести в `machine_categories`, `unlisted_machinery = null` (страховка: категорию завели, модель не заметила).
2. В `handleCollecting()` до интейка: `matchesButton(BUTTON_MACHINERY_UNLISTED)` и заполненная запомненная/текущая `unlisted_machinery` → `machinery_skipped = true`, транскрипт не пополняется, модель не вызывается → `advance()`.
3. Недостающие поля: `machine_categories` не считается недостающим, когда `machinery_skipped === true`.
4. В `advance()` после цикла кнопочных полей и до проверки лимита: если не спрашивается имя, `machine_categories` в недостающих и `unlisted_machinery` заполнена — при `unlisted_prompts < MAX_UNLISTED_PROMPTS` инкремент, фаза `collecting`, промпт (текст №1) с кнопками [Нет в списке][В меню], попытка не тратится, `InProgress`; иначе `machinery_skipped = true` и пересчёт недостающих (продолжить `advance`).
5. Детектор: на пути текстового уточнения (после существующей проверки `attempts`): `stalled_missing === $missing` → `stalled_turns++`, иначе `stalled_missing = $missing`, `stalled_turns = 0`; при `stalled_turns >= MAX_STALLED_TURNS` → `handOffToWebForm`. Кнопочные промпты, списки мест, промпт «Нет в списке», нечитаемые и сервисные вопросы счётчик не трогают.
6. `listingAttributes()` водителя: `'unlisted_machinery' => $fields['unlisted_machinery'] ?? null`.
7. `buildSummary()` водителя: строка техники = категории + техника словами через `, `.
8. `sendConfirmation()`: если `unlisted_machinery` заполнена и `machine_categories` пуст — после сводки строка №2.

**Веб-форма (водитель):** `machine_categories` → `['required_without:unlisted_machinery', 'array']`, `machine_categories.*` без изменений; новое `unlisted_machinery` → `['nullable', 'string', 'max:120']`. Сообщение `machine_categories.required_without` — текст №4. Атрибут `unlisted_machinery` → «техника словами». Контроллер: поле попадает в `fill()` через `safe()->except(...)` как есть.

**Админка:** `TextInput::make('unlisted_machinery')` сразу после `machine_categories`, только водитель, `maxLength(120)`, label/helper — тексты №5/№6. В таблице — колонка `unlisted_machinery` с label «Техника вне справочника», badge warning, `toggleable()`, `placeholder('—')`.

### Копирайт-таблица

| № | Где | Текст |
|---|---|---|
| 1 | Промпт коллектора | `«{Слово}» в нашем списке техники пока нет. Если работаете ещё на чём-то из списка — например, экскаватор, самосвал, кран — напишите. Если нет — нажмите «Нет в списке», и категорию подберёт оператор.` — `{Слово}` = `unlisted_machinery`, trim, первая буква заглавная (`Str::ucfirst`) |
| 2 | Строка в сводке | `Техника «{Слово}» — не из нашего списка: категорию подберёт оператор при проверке.` |
| 3 | Кнопка | `Нет в списке` |
| 4 | Ошибка веб-формы | `Отметьте технику из списка или напишите её словами.` |
| 5 | Админка, label | `Техника вне справочника` |
| 6 | Админка, helper | `Водитель назвал технику, которой нет в справочнике. Заведите категорию, отметьте её в поле выше и очистите эту строку.` |
| 7 | Веб-форма, label | `Техники нет в списке? Напишите словами` |
| 8 | Веб-форма, placeholder | `Например: автобус` |
| 9 | Веб-форма, подсказка под полем | `Оператор подберёт категорию при проверке.` |

---

### Task 1: Фундамент — колонка, модель, экстрактор, матчер, эмбеддинги

`ENV_FILE`: `<scratchpad>/.env.test_b`

**Files:**
- Modify: `database/migrations/2026_09_05_121344_add_unlisted_machinery_to_listings_table.php` (уже создан пустым)
- Modify: `app/Models/Listing.php`, `database/factories/ListingFactory.php`
- Modify: `app/Ai/Agents/ListingExtractionAgent.php`
- Modify: `app/Services/Ai/ListingEmbeddings.php`, `app/Services/Ai/ListingMatcher.php`
- Test: `tests/Feature/ListingKindTest.php`, `tests/Feature/ListingEmbeddingTest.php`, `tests/Feature/ListingMatcherTest.php`

**Interfaces:**
- Produces: колонка, `Listing::machineryLine()`, поле `unlisted_machinery` в схеме и промпте водителя. Задачи 2–4 полагаются на них.

- [ ] **Step 1: Failing-тесты** — `ListingKindTest`: `machineryLine()` отдаёт «Экскаватор, Автобус» при категории + тексте, «Автобус» при одном тексте, null при пустоте. `ListingEmbeddingTest`: `sourceText()` водителя содержит «Техника: Экскаватор, Автобус» (и «Техника: Автобус» без категорий). `ListingMatcherTest`: водитель без категорий, но с `unlisted_machinery = 'автобус'` находится по запросу «нужен водитель автобуса» (по образцу теста на строке ~298; описание задать явно — фабрика случайна). Схему экстрактора проверять не здесь (Task 2).
- [ ] **Step 2: Миграция** — `$table->string('unlisted_machinery', 120)->nullable();` с PHPDoc-объяснением (техника словами водителя, которой нет в справочнике; оператор заводит категорию на модерации); `down()` — dropColumn.
- [ ] **Step 3: Модель и фабрика** — `#[Fillable]` + `EMBEDDING_SOURCE_FIELDS` + метод `machineryLine()` с PHPDoc; фабрика `driver()` — явный null.
- [ ] **Step 4: Экстрактор** — в `driverFields()` пункт `- unlisted_machinery: ...` (текст контракта) сразу после `machine_categories`; в подсказке `machine_categories` добавить: «техника, которой нет в списке, идёт в unlisted_machinery»; в `schema()` водителя — поле; обновить класс-PHPDoc (абзац про словари).
- [ ] **Step 5: Матчер и эмбеддинги** — `ListingEmbeddings::sourceText()` водителя: строка `Техника: ` из `machineryLine()`; `ListingMatcher::score()` — в haystack добавить `$listing->unlisted_machinery`.
- [ ] **Step 6: Прогон и pint** — `make test ENV_FILE=... test_args="--compact --filter='ListingKindTest|ListingEmbeddingTest|ListingMatcherTest|AiAgentStrictSchemaTest'"`; pint на своих файлах.

### Task 2: Коллектор — честный промпт, кнопка, закрытие поля, детектор холостого хода

`ENV_FILE`: `<scratchpad>/.env.test_b` (после Task 1)

**Files:**
- Modify: `app/Services/Ai/SupplierListingCollector.php`
- Modify: `app/Enums/ListingKind.php` (только PHPDoc у `collectorRequiredFields()` — техника обязательна с выходом «нет в списке»; текст фолбэк-вопроса не менять)
- Test: `tests/Feature/SupplierListingCollectorTest.php`

**Interfaces:**
- Consumes: Task 1 (колонка, поле схемы).
- Produces: state-ключи и константы из контракта; кнопка `collect_machinery_unlisted`.

- [ ] **Step 1: Failing-тесты** (хелпер `driverExtraction([...])`, `collectorSession(['kind' => 'driver', ...])`, `fakeCollectorMessenger()`):
  (а) извлечение с `machine_categories => null, unlisted_machinery => 'автобус'`, остальное заполнено → `sendButtons` с текстом №1 (`«Автобус» в нашем списке…`) и кнопками `[collect_machinery_unlisted 'Нет в списке', collect_to_menu]`; `attempts` = 0, `unlisted_prompts` = 1, фаза `collecting`;
  (б) нажатие кнопки (replyId) при запомненной технике → без вызова модели (`preventStrayPrompts`/`assertNeverPrompted`) `machinery_skipped` = true, фаза `confirming` (`awaiting_document` = true — документа нет), черновик: `unlisted_machinery = 'автобус'`, `machineCategories` пусто; в сводке строка №2;
  (в) набранное «нет в списке» = нажатие;
  (г) `unlisted_prompts = 2` и всё то же → промпт не уходит, поле закрывается, сводка;
  (д) липкость: `state.unlisted_machinery = 'автобус'`, модель на новом ходу вернула `unlisted_machinery => null` → в полях и черновике остаётся «автобус»;
  (е) страховка: `unlisted_machinery => 'Экскаватор'` при категории «Экскаватор» в справочнике → в `machine_categories` попадает «Экскаватор», `unlisted_machinery` = null, промпт не уходит;
  (ж) детектор: сессия с `stalled_missing = ['price'], stalled_turns = 1`, извлечение снова без цены → `sendCtaUrl` (веб-форма), а не вопрос; с `stalled_turns = 0` → ещё вопрос и `stalled_turns` = 1; ход, заполнивший поле (набор недостающих изменился), сбрасывает `stalled_turns` в 0;
  (з) промпт «Нет в списке» и кнопочные промпты счётчик холостого хода не трогают;
  (и) `handOffToWebForm`/`exitToMenu` сохраняют `unlisted_machinery` в черновик;
  (к) `buildSummary()` водителя без категорий с техникой словами содержит «автобус»;
  (л) в тесте схемы (по образцу «the extraction schema and prompt hard-limit the category…», ~строка 1050) — схема водителя содержит `unlisted_machinery`, промпт упоминает его.
  Существующие тесты «лимит уточнений у водителя — шесть» и др. должны остаться зелёными: `stalled_missing` по умолчанию null.
- [ ] **Step 2: Константы и state** — константы с PHPDoc (зачем максимум два промпта; зачем детектор — инцидент 225); ключи в `start()` и `normalizeState()`.
- [ ] **Step 3: `extract()`** — правило 1 контракта (страховка совпадения).
- [ ] **Step 4: `handleCollecting()`** — правило 2 (кнопка до интейка); липкость `unlisted_machinery` рядом с реаппликацией `button_answers` (значение из слов побеждает).
- [ ] **Step 5: `advance()`** — правило 3 (недостающие без закрытого поля — через хелпер, чтобы `missingFields` осталась чистой функцией), правило 4 (промпт), правило 5 (детектор). Порядок в `advance()`: имя → места → кнопочные поля → промпт «Нет в списке» → лимит попыток → детектор → текстовый вопрос.
- [ ] **Step 6: Черновик и сводка** — правила 6–8.
- [ ] **Step 7: Прогон и pint** — `--filter=SupplierListingCollectorTest` (весь файл, 123+ тестов); pint на своих файлах.

### Task 3: Веб-кабинет и витрина

`ENV_FILE`: `<scratchpad>/.env.test_c` (после Task 1)

**Files:**
- Modify: `app/Http/Requests/UpdateSupplierListingRequest.php`
- Modify: `resources/views/supplier/listing-edit.blade.php`, `resources/views/supplier/listings-index.blade.php`
- Modify: `resources/views/customer/catalog.blade.php`, `resources/views/customer/listing-show.blade.php`
- Modify: `resources/views/storefront-design-preview.blade.php`
- Modify (только если строка выдачи в чате показывает технику водителя — проверить `CustomerSearchAssistant` около строки 938): `app/Services/Ai/CustomerSearchAssistant.php`
- Test: `tests/Feature/SupplierPortalTest.php`, `tests/Feature/CustomerCatalogTest.php`, `tests/Feature/ListingPreviewTest.php` (если показывает технику), `tests/Feature/CustomerSearchAssistantTest.php` (если менялась строка выдачи)

**Interfaces:**
- Consumes: Task 1 (`machineryLine()`, колонка).

- [ ] **Step 1: Failing-тесты** — `SupplierPortalTest`: (а) форма водителя без `machine_categories`, но с `unlisted_machinery = 'автобус'` уходит на модерацию, поле сохранено; (б) без обоих — ошибка `machine_categories` с текстом №4; (в) `unlisted_machinery` длиннее 120 — ошибка; (г) форма рендерит `name="unlisted_machinery"`, label №7, подсказку №9; (д) просмотр анкеты на модерации показывает «Экскаватор, Автобус» одной строкой; (е) в «Мои объявления» подзаголовок водителя — имя · техника, включая словами. `CustomerCatalogTest`: карточка и страница водителя показывают `machineryLine()` («Автобус» без категорий; «Экскаватор, Автобус» с обеими). Существующий тест на строке ~442 (`assertSessionHasErrors([... 'machine_categories' ...])`) остаётся: без обоих полей ошибка по-прежнему на `machine_categories`.
- [ ] **Step 2: Правила формы** — контракт; сообщение №4; атрибут.
- [ ] **Step 3: Blade кабинета** — после блока чекбоксов техники поле `unlisted_machinery` (label №7, placeholder №8, подсказка №9 классом подсказки, как у соседних полей; `old()`), ошибки; в `dl` просмотра и в подзаголовке `listings-index` — `machineryLine()`.
- [ ] **Step 4: Витрина** — в `catalog.blade.php` и `listing-show.blade.php` строка техники водителя из `machineryLine()` (условие — `filled(...)`).
- [ ] **Step 5: Превью дизайна** — в секции «Портал поставщика — анкета водителя, mobile 375px» добавить то же поле после чекбоксов, в точности по вёрстке production-формы; заголовок секции дополнить «техника словами». Остальные секции структурно не меняются (строка техники та же).
- [ ] **Step 6: Прогон и pint** — `--filter='SupplierPortalTest|CustomerCatalogTest|ListingPreviewTest'`; pint на PHP-файлах.

### Task 4: Админка

`ENV_FILE`: `<scratchpad>/.env.test_d` (после Task 1)

**Files:**
- Modify: `app/Filament/Resources/Listings/Schemas/ListingForm.php`, `app/Filament/Resources/Listings/Tables/ListingsTable.php`
- Test: `tests/Feature/ListingAdminTest.php`, `tests/Feature/ListingModerationPanelTest.php`

**Interfaces:**
- Consumes: Task 1 (колонка).

- [ ] **Step 1: Failing-тесты** — `ListingAdminTest`: в матрице видимости полей по виду (строки ~49–58) `unlisted_machinery` виден у водителя и скрыт у аренды/мастера; редактирование водителя сохраняет `unlisted_machinery`; таблица показывает колонку у водителя с текстом словами (`assertCanRenderTableColumn`/`assertSee`). `ListingModerationPanelTest`: при открытии водителя на модерации оператор видит текст словами и helper №6.
- [ ] **Step 2: Форма** — `TextInput` по контракту (label №5, helper №6, `maxLength(120)`, `visible` водитель), сразу после `machine_categories`.
- [ ] **Step 3: Таблица** — колонка по контракту.
- [ ] **Step 4: Прогон и pint** — `--filter='ListingAdminTest|ListingModerationPanelTest|ListingCrudPanelTest'`; pint на своих файлах. Заглянуть в `tools/demo-video/scenario.mjs`: если он заполняет форму объявления водителя по порядку полей — записать в отчёт (править не нужно, если поле не мешает).

### Task 5: Документация

Тесты не гоняются.

**Files:**
- Modify: `docs/modules/ai-assistant.md` (строки ~34, 38, 54 и абзац о кнопках ~51), `docs/business-rules.md` (~9, 49), `docs/modules/listings-lifecycle.md` (~26, 48, раздел модерации), `docs/modules/whatsapp-integration.md` (~127 «Редактирование», ~125 «Мои объявления» подзаголовок), `docs/modules/user-flows.md` (~39), `docs/changelog.md` (новая запись 2026-09-05)
- Modify: `.ai/guidelines/project-documentation.md`, `CLAUDE.md`, `AGENTS.md` — строка «clarification question limits (2–3 attempts)» → «clarification question limits (per kind: 3 / 4 / 6 attempts, plus a no-progress cutoff)» во всех трёх одинаково (CLAUDE.md и AGENTS.md генерируются из `.ai/`, но правятся синхронно руками)

- [ ] **Step 1: ai-assistant.md** — состав анкеты водителя: техника из справочника **или словами, если её в справочнике нет**; в абзаце о справочнике — новый случай «справочник непустой, но нужного нет»: бот честно называет слово и объясняет, что в списке его нет, предлагает назвать технику из списка или нажать «Нет в списке»; промпт не тратит попытку и уходит максимум дважды, затем поле закрывается само; закрытое поле не мешает сводке и отправке на модерацию; названное словами сохраняется, показывается в сводке с пометкой «категорию подберёт оператор» и попадает к оператору; в абзаце об уточнениях — детектор холостого хода: третий подряд текстовый уточняющий вопрос, после которого набор недостающих полей не изменился, не задаётся — черновик и CTA на веб-форму, как при исчерпании лимита; счёт сбрасывается любым ходом, заполнившим поле; кнопочные вопросы, списки мест и сервисные вопросы его не трогают.
- [ ] **Step 2: business-rules.md** — техника водителя: из справочника, либо словами при отсутствии в справочнике (тогда объявление уходит на модерацию с пометкой, оператор заводит категорию и привязывает до публикации); правило о категории — про водителя дописать «названная, но отсутствующая в справочнике техника сохраняется словами».
- [ ] **Step 3: listings-lifecycle.md** — поле «Техника»: плюс «техника словами» (что это, кто видит, что с ней делает оператор: завести категорию, отметить, очистить строку); правило публикации — техника словами не блокирует; модерация — шаг оператора по технике вне справочника; веб-каталог показывает технику словами вместе с категориями, пока оператор не привёл к справочнику.
- [ ] **Step 4: whatsapp-integration.md, user-flows.md** — веб-форма: техника — из списка или словами; шаг 2 анкеты водителя: техника из списка или «Нет в списке».
- [ ] **Step 5: changelog.md** — запись «2026-09-05»: суть инцидента (без имён), что изменилось (пять пунктов: честный промпт + кнопка, закрытие поля, пометка оператору, веб-форма, детектор холостого хода), модули 2, 3, 4.
- [ ] **Step 6: Гайдлайн** — правка лимитов в трёх файлах.

### Task 6: Ревью и общий прогон

- [ ] Адверсариальное ревью каждой задачи по диффу против контрактов и копирайт-таблицы; критик полноты — что не покрыто (места показа техники, тесты, доки).
- [ ] Полный прогон `make test` (общая БД `sala_testing`, один процесс) и `docker exec sala-app-1 vendor/bin/pint --dirty --format agent`.
