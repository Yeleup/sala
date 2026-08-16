# Главное меню бота тремя категориями — план реализации

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Заменить главное меню типового сценария бота — интерактивный список из семи строк — на два шага reply-кнопок: сначала вид услуги (аренда / ремонт / водитель), затем роль внутри вида плюс «Мои объявления».

**Architecture:** Меняется только определение типового сценария в `InstallDefaultBotScenario::mainDialogDefinition()`. Узел `main_menu` типа `list` становится узлом типа `buttons` с тремя кнопками видов; добавляются три узла второго шага `menu_rental`, `menu_repair`, `menu_driver` — тоже `buttons`, по три кнопки. AI-узлы, завершающие тексты, блок «Мои объявления» и оба флоу-сценария не трогаются: к ним просто ведут другие рёбра. Движок, валидатор, `DereuMessenger` и редактор сценариев уже поддерживают кнопочные меню и маршрутизацию по id — кода вне команды-установщика не добавляется.

**Tech Stack:** PHP 8.4, Laravel 13, Pest 4. Всё исполняется в Docker (`make ...`).

Спека: [docs/superpowers/specs/2026-08-13-main-menu-by-category-design.md](../specs/2026-08-13-main-menu-by-category-design.md).

## Global Constraints

- Лимит WhatsApp: **3 reply-кнопки** в одном сообщении, подпись — **до 20 символов** (`ScenarioValidator::MAX_BUTTONS`, `ScenarioValidator::MAX_BUTTON_TITLE_LENGTH`).
- **Прежние семь id вариантов не переименовываются**: `rent_out`, `rent_seek`, `master`, `master_seek`, `driver`, `driver_seek`, `my` переезжают на новые узлы как есть. Маршрутизация идёт по машинному id, кнопки прежней версии сценария висят в чатах контактов и обязаны продолжать работать.
- Новых id ровно пять: `kind_rental`, `kind_repair`, `kind_driver`, `my_repair`, `my_driver`. Категорию **нельзя** назвать `driver` (id занят ролью), ни один вариант **нельзя** назвать `my_listings` (перехватывается до входа в граф).
- Подписи кнопок — дословно из спеки: «Аренда спецтехники», «Ремонт спецтехники», «Водитель / машинист», «Я сдаю спецтехнику», «Я ищу спецтехнику», «Я мастер», «Я ищу мастера», «Я водитель», «Я ищу водителя», «Мои объявления».
- Тесты запускаются **только** через `make test` (прямой `php artisan test` бьёт по dev-базе, и `RefreshDatabase` её вытирает).
- PHP, composer, pint — только внутри контейнера (`make artisan`, `make composer`, `make shell`).
- Никаких изменений в `BotEngine`, `ScenarioValidator`, `ScenarioDefinition`, `DereuMessenger` и редакторе сценариев.

---

### Task 1: Типовой главный диалог — два шага на кнопках

**Files:**
- Modify: `app/Console/Commands/InstallDefaultBotScenario.php:86-146` (докблок и `mainDialogDefinition()`)
- Test: `tests/Feature/InstallDefaultBotScenarioTest.php:10-58` (два существующих теста переписываются, добавляется третий)

**Interfaces:**
- Consumes: `ScenarioDefinition::target(string $nodeId, string $output): ?string`, `ScenarioDefinition::optionOwner(string $optionId): ?array{node_id: string, option_id: string}`, `ScenarioDefinition::optionOutput(string $optionId): string` (**статический метод**), `ScenarioDefinition::startNodeId(): ?string`, константы `ScenarioDefinition::OUTPUT_CONTINUE` / `OUTPUT_RETURNING`, `ScenarioValidator::validate(array $definition, BotScenarioTrigger $trigger): array{errors: list<string>, warnings: list<string>}`.
- Produces: узлы главного диалога `main_menu`, `menu_rental`, `menu_repair`, `menu_driver` типа `buttons`; прежние id вариантов сохранены. Ничего нового наружу не экспортируется.

- [ ] **Step 1: Переписать первый тест под новую структуру меню**

В `tests/Feature/InstallDefaultBotScenarioTest.php` заменить тест `the installer publishes the reference main dialog with every MVP branch` (строки 10–31) целиком на:

```php
test('the installer publishes the reference main dialog with every MVP branch', function () {
    $this->artisan('bot:install-default-scenario')->assertSuccessful();

    $scenario = BotScenario::main();
    expect($scenario->isPublished())->toBeTrue();

    $definition = new ScenarioDefinition($scenario->published_definition);
    $nodes = collect($scenario->published_definition['nodes']);

    expect($nodes->firstWhere('id', 'main_menu')['options'])->toHaveCount(3)
        ->and($nodes->firstWhere('id', 'collect_rental')['task'])->toBe('collect_listing')
        ->and($nodes->firstWhere('id', 'search_rental')['task'])->toBe('customer_search')
        ->and($nodes->firstWhere('id', 'my_listings')['type'])->toBe('my_listings')
        ->and($definition->startNodeId())->toBe('start')
        // Повторное обращение ведёт сразу к меню, минуя приветствие.
        ->and($definition->target('start', ScenarioDefinition::OUTPUT_RETURNING))->toBe('main_menu')
        ->and($definition->target('start', ScenarioDefinition::OUTPUT_CONTINUE))->toBe('greeting')
        // Первый шаг разводит по видам, второй — по роли внутри вида.
        ->and($definition->target('main_menu', 'option:kind_rental'))->toBe('menu_rental')
        ->and($definition->target('main_menu', 'option:kind_repair'))->toBe('menu_repair')
        ->and($definition->target('main_menu', 'option:kind_driver'))->toBe('menu_driver')
        ->and($definition->target('menu_rental', 'option:rent_out'))->toBe('collect_rental')
        ->and($definition->target('menu_rental', 'option:rent_seek'))->toBe('search_rental')
        ->and($definition->target('menu_rental', 'option:my'))->toBe('my_listings');
});
```

- [ ] **Step 2: Переписать второй тест — состав четырёх кнопочных меню**

Там же заменить тест `типовой главный сценарий — меню-список из шести веток с видами на AI-узлах` (строки 33–58) целиком на:

```php
test('типовое меню — два шага на кнопках: вид услуги, затем роль', function () {
    $this->artisan('bot:install-default-scenario --force')->assertSuccessful();

    $scenario = BotScenario::main();
    $definition = new ScenarioDefinition($scenario->published_definition);
    $nodes = collect($scenario->published_definition['nodes']);

    // Четыре пункта верхнего уровня кнопками в одно сообщение не помещаются
    // (лимит WhatsApp — 3), поэтому меню разложено на два шага;
    // узлов-списков в главном диалоге не осталось.
    expect($nodes->where('type', 'list'))->toBeEmpty();

    // Состав и порядок кнопок каждого экрана зафиксирован поимённо: это же
    // держит уникальность id — валидатор дубликаты не ловит.
    foreach ([
        'main_menu' => ['kind_rental', 'kind_repair', 'kind_driver'],
        'menu_rental' => ['rent_out', 'rent_seek', 'my'],
        'menu_repair' => ['master', 'master_seek', 'my_repair'],
        'menu_driver' => ['driver', 'driver_seek', 'my_driver'],
    ] as $nodeId => $optionIds) {
        $menu = $nodes->firstWhere('id', $nodeId);

        expect($menu['type'])->toBe('buttons')
            ->and(collect($menu['options'])->pluck('id')->all())->toBe($optionIds);
    }

    // Все три кнопки «Мои объявления» ведут в один блок CTA.
    expect($definition->target('menu_rental', 'option:my'))->toBe('my_listings')
        ->and($definition->target('menu_repair', 'option:my_repair'))->toBe('my_listings')
        ->and($definition->target('menu_driver', 'option:my_driver'))->toBe('my_listings');

    foreach ([
        'collect_rental' => ['collect_listing', 'rental'],
        'collect_repair' => ['collect_listing', 'repair'],
        'collect_driver' => ['collect_listing', 'driver'],
        'search_rental' => ['customer_search', 'rental'],
        'search_repair' => ['customer_search', 'repair'],
        'search_driver' => ['customer_search', 'driver'],
    ] as $id => [$task, $kind]) {
        // У каждой ветки — своя пара задача+вид, и выход «Продолжить» подключен.
        expect($nodes->firstWhere('id', $id))->toMatchArray(['task' => $task, 'kind' => $kind])
            ->and($definition->target($id, ScenarioDefinition::OUTPUT_CONTINUE))->not->toBeNull();
    }
});
```

- [ ] **Step 3: Добавить третий тест — сохранность прежних id и лимиты WhatsApp**

Вставить сразу после теста из шага 2:

```php
test('прежние id вариантов сохранены — кнопки из старых чатов ведут в те же ветки', function () {
    $this->artisan('bot:install-default-scenario')->assertSuccessful();

    $definition = new ScenarioDefinition(BotScenario::main()->published_definition);

    // Маршрутизация идёт по машинному id и работает с любого шага, поэтому
    // строки прежнего семистрочного списка, висящие в чатах контактов,
    // обязаны вести туда же, куда вели.
    foreach ([
        'rent_out' => 'collect_rental',
        'rent_seek' => 'search_rental',
        'master' => 'collect_repair',
        'master_seek' => 'search_repair',
        'driver' => 'collect_driver',
        'driver_seek' => 'search_driver',
        'my' => 'my_listings',
    ] as $optionId => $expectedTarget) {
        $owner = $definition->optionOwner($optionId);

        expect($owner)->not->toBeNull()
            ->and($definition->target($owner['node_id'], ScenarioDefinition::optionOutput($optionId)))
            ->toBe($expectedTarget);
    }
});

test('типовой главный диалог укладывается в лимиты WhatsApp', function () {
    $this->artisan('bot:install-default-scenario')->assertSuccessful();

    $definition = BotScenario::main()->published_definition;

    ['errors' => $errors] = app(ScenarioValidator::class)
        ->validate($definition, BotScenarioTrigger::InboundMessage);

    expect($errors)->toBe([]);

    foreach (collect($definition['nodes'])->where('type', 'buttons') as $menu) {
        expect($menu['options'])->toHaveCount(3);

        foreach ($menu['options'] as $option) {
            expect(mb_strlen($option['title']))->toBeLessThanOrEqual(20);
        }
    }
});
```

- [ ] **Step 4: Добавить импорт валидатора в шапку теста**

В `tests/Feature/InstallDefaultBotScenarioTest.php` после строки `use App\Services\Bot\ScenarioDefinition;` добавить:

```php
use App\Services\Bot\ScenarioValidator;
```

- [ ] **Step 5: Прогнать тесты и убедиться, что они падают**

```bash
make test test_args="--compact --filter=InstallDefaultBotScenario"
```

Ожидаемо: FAIL. Первый тест падает на `toHaveCount(3)` (в меню семь опций), второй — на `expect($nodes->where('type', 'list'))->toBeEmpty()`, третий — на `optionOwner('master')`, который сейчас находит владельца `main_menu`, а `target('main_menu', 'option:master')` даёт `collect_repair` — этот тест может пройти и до правки, это нормально: он страхует от регрессии.

- [ ] **Step 6: Переписать докблок `mainDialogDefinition()`**

В `app/Console/Commands/InstallDefaultBotScenario.php` заменить докблок на строках 86–93 на:

```php
    /**
     * Главный диалог: меню в два шага на кнопках. Первый шаг — три вида
     * объявлений (аренда, ремонт, водитель); второй — роль внутри вида
     * («сдаю» / «ищу») плюс «Мои объявления» третьей кнопкой. Четыре
     * пункта верхнего уровня в одно сообщение не помещаются: лимит
     * WhatsApp — три reply-кнопки. Прежние id вариантов сохранены, чтобы
     * кнопки прежней версии сценария, висящие в чатах, продолжали
     * работать. Текст AI-блокам не задаётся: приветствие подставляется
     * из выбранного вида.
     *
     * @return array{nodes: list<array<string, mixed>>, edges: list<array<string, mixed>>}
     */
```

- [ ] **Step 7: Заменить узлы главного диалога**

В том же файле заменить массив `'nodes'` (строки 97–125) целиком на:

```php
            'nodes' => [
                ['id' => 'start', 'type' => 'start', 'x' => 40, 'y' => 400],
                ['id' => 'greeting', 'type' => 'text', 'x' => 260, 'y' => 400,
                    'text' => 'Здравствуйте! Это сервис спецтехники: аренда, ремонт, водители и машинисты. Разместите своё предложение или найдите исполнителя.'],
                ['id' => 'main_menu', 'type' => 'buttons', 'x' => 500, 'y' => 400,
                    'text' => 'Выберите раздел.',
                    'options' => [
                        ['id' => 'kind_rental', 'title' => 'Аренда спецтехники'],
                        ['id' => 'kind_repair', 'title' => 'Ремонт спецтехники'],
                        ['id' => 'kind_driver', 'title' => 'Водитель / машинист'],
                    ]],
                ['id' => 'menu_rental', 'type' => 'buttons', 'x' => 740, 'y' => 140,
                    'text' => 'Аренда спецтехники. Что вам нужно?',
                    'options' => [
                        ['id' => 'rent_out', 'title' => 'Я сдаю спецтехнику'],
                        ['id' => 'rent_seek', 'title' => 'Я ищу спецтехнику'],
                        ['id' => 'my', 'title' => 'Мои объявления'],
                    ]],
                ['id' => 'menu_repair', 'type' => 'buttons', 'x' => 740, 'y' => 400,
                    'text' => 'Ремонт спецтехники. Что вам нужно?',
                    'options' => [
                        ['id' => 'master', 'title' => 'Я мастер'],
                        ['id' => 'master_seek', 'title' => 'Я ищу мастера'],
                        ['id' => 'my_repair', 'title' => 'Мои объявления'],
                    ]],
                ['id' => 'menu_driver', 'type' => 'buttons', 'x' => 740, 'y' => 660,
                    'text' => 'Водители и машинисты. Что вам нужно?',
                    'options' => [
                        ['id' => 'driver', 'title' => 'Я водитель'],
                        ['id' => 'driver_seek', 'title' => 'Я ищу водителя'],
                        ['id' => 'my_driver', 'title' => 'Мои объявления'],
                    ]],
                ['id' => 'collect_rental', 'type' => 'ai', 'task' => 'collect_listing', 'kind' => 'rental', 'x' => 1000, 'y' => 40],
                ['id' => 'search_rental', 'type' => 'ai', 'task' => 'customer_search', 'kind' => 'rental', 'x' => 1000, 'y' => 160],
                ['id' => 'collect_repair', 'type' => 'ai', 'task' => 'collect_listing', 'kind' => 'repair', 'x' => 1000, 'y' => 300],
                ['id' => 'search_repair', 'type' => 'ai', 'task' => 'customer_search', 'kind' => 'repair', 'x' => 1000, 'y' => 420],
                ['id' => 'collect_driver', 'type' => 'ai', 'task' => 'collect_listing', 'kind' => 'driver', 'x' => 1000, 'y' => 560],
                ['id' => 'search_driver', 'type' => 'ai', 'task' => 'customer_search', 'kind' => 'driver', 'x' => 1000, 'y' => 680],
                ['id' => 'my_listings', 'type' => 'my_listings', 'x' => 1000, 'y' => 820,
                    'text' => 'Откройте кабинет — там ваши объявления, статусы, причины отклонения и снятие с публикации.'],
                ['id' => 'after_collect', 'type' => 'text', 'x' => 1260, 'y' => 300,
                    'text' => 'Чтобы добавить ещё одно объявление или найти исполнителя — просто напишите нам снова.'],
                ['id' => 'after_search', 'type' => 'text', 'x' => 1260, 'y' => 560,
                    'text' => 'Спасибо, что воспользовались сервисом! Напишите нам снова, когда что-то понадобится.'],
            ],
```

Поле `'button' => 'Выбрать'` исчезает вместе с типом `list`: подпись раскрывающей кнопки нужна только интерактивному списку.

- [ ] **Step 8: Заменить рёбра главного диалога**

В том же файле заменить массив `'edges'` (строки 126–144) целиком на:

```php
            'edges' => [
                ['from' => 'start', 'output' => 'continue', 'to' => 'greeting'],
                // Повторное обращение: без приветствия — сразу меню разделов.
                ['from' => 'start', 'output' => 'returning', 'to' => 'main_menu'],
                ['from' => 'greeting', 'output' => 'continue', 'to' => 'main_menu'],
                ['from' => 'main_menu', 'output' => 'option:kind_rental', 'to' => 'menu_rental'],
                ['from' => 'main_menu', 'output' => 'option:kind_repair', 'to' => 'menu_repair'],
                ['from' => 'main_menu', 'output' => 'option:kind_driver', 'to' => 'menu_driver'],
                ['from' => 'menu_rental', 'output' => 'option:rent_out', 'to' => 'collect_rental'],
                ['from' => 'menu_rental', 'output' => 'option:rent_seek', 'to' => 'search_rental'],
                ['from' => 'menu_rental', 'output' => 'option:my', 'to' => 'my_listings'],
                ['from' => 'menu_repair', 'output' => 'option:master', 'to' => 'collect_repair'],
                ['from' => 'menu_repair', 'output' => 'option:master_seek', 'to' => 'search_repair'],
                ['from' => 'menu_repair', 'output' => 'option:my_repair', 'to' => 'my_listings'],
                ['from' => 'menu_driver', 'output' => 'option:driver', 'to' => 'collect_driver'],
                ['from' => 'menu_driver', 'output' => 'option:driver_seek', 'to' => 'search_driver'],
                ['from' => 'menu_driver', 'output' => 'option:my_driver', 'to' => 'my_listings'],
                ['from' => 'collect_rental', 'output' => 'continue', 'to' => 'after_collect'],
                ['from' => 'collect_repair', 'output' => 'continue', 'to' => 'after_collect'],
                ['from' => 'collect_driver', 'output' => 'continue', 'to' => 'after_collect'],
                ['from' => 'search_rental', 'output' => 'continue', 'to' => 'after_search'],
                ['from' => 'search_repair', 'output' => 'continue', 'to' => 'after_search'],
                ['from' => 'search_driver', 'output' => 'continue', 'to' => 'after_search'],
            ],
```

- [ ] **Step 9: Прогнать тесты и убедиться, что они проходят**

```bash
make test test_args="--compact --filter=InstallDefaultBotScenario"
```

Ожидаемо: PASS, все тесты файла (включая нетронутые про флоу-сценарии и `--force`).

- [ ] **Step 10: Прогнать смежные тесты бота**

```bash
make test test_args="--compact --filter=Bot"
```

Ожидаемо: PASS. Если падает тест движка или редактора — это регрессия, а не ожидаемое следствие: план не меняет ничего вне определения типового сценария.

- [ ] **Step 11: Прогнать Pint**

```bash
docker exec sala-app-1 vendor/bin/pint --dirty --format agent
```

Ожидаемо: либо «no issues», либо переформатированный `InstallDefaultBotScenario.php`. Pint на хосте не запускать — версия PHP отличается от контейнерной.

- [ ] **Step 12: Коммит**

```bash
git add app/Console/Commands/InstallDefaultBotScenario.php tests/Feature/InstallDefaultBotScenarioTest.php
git commit -m "Главное меню бота: три категории на кнопках, роль вторым шагом"
```

---

### Task 2: Документация

**Files:**
- Modify: `docs/technical-specification.md:18`, `:40`, `:53`, `:124`, `:128`, `:132`, `:136`
- Modify: `docs/business-rules.md:8`
- Modify: `docs/modules/user-flows.md:11`, `:14`, `:21`, `:29`, `:35`, `:43`, `:48`
- Modify: `docs/modules/bot-constructor.md:122`
- Modify: `docs/modules/ai-assistant.md:55`
- Modify: `docs/changelog.md` (новая запись сверху)

**Interfaces:**
- Consumes: структура меню из Task 1 (узлы `main_menu`, `menu_rental`, `menu_repair`, `menu_driver`; подписи кнопок).
- Produces: ничего исполняемого.

Правило проекта: доки описывают **поведение**, а не реализацию. Названия узлов, классов и полей в доки не переносятся — только то, что видит пользователь.

- [ ] **Step 1: `docs/business-rules.md` — правило о составе меню**

Заменить строку 8 целиком на:

```markdown
- Главное меню — два шага на кнопках. Первый шаг: «Аренда спецтехники», «Ремонт спецтехники», «Водитель / машинист». Второй шаг внутри выбранного раздела: «Я сдаю спецтехнику» / «Я ищу спецтехнику», «Я мастер» / «Я ищу мастера», «Я водитель» / «Я ищу водителя», и третьей кнопкой на каждом экране — «Мои объявления». Четвёртой кнопки на первом шаге быть не может: лимит WhatsApp — три кнопки в сообщении.
```

- [ ] **Step 2: `docs/technical-specification.md` — пять мест**

Строка 18: заменить фрагмент в скобках

```
(главное меню — список веток по видам объявлений: [Сдаю спецтехнику], [Ищу спецтехнику], [Я мастер], [Ищу мастера], [Я водитель], [Ищу водителя], [Мои объявления])
```

на

```
(главное меню — два шага на кнопках: разделы [Аренда спецтехники], [Ремонт спецтехники], [Водитель / машинист], а внутри раздела — роль и [Мои объявления])
```

Строка 40: заменить `заказчик из ветки «Ищу мастера» видит только мастеров` на `заказчик из ветки «Я ищу мастера» видит только мастеров`.

Строка 53: заменить `при нажатии кнопки [Мои объявления]` на `при нажатии кнопки [Мои объявления] на экране любого раздела`.

Строка 124: заменить предложение

```
приветствие и главное меню — интерактивный список из семи строк: [Сдаю спецтехнику], [Ищу спецтехнику], [Я мастер], [Ищу мастера], [Я водитель], [Ищу водителя], [Мои объявления]. Выбранная ветка задаёт вид объявления
```

на

```
приветствие и главное меню — три кнопки разделов: [Аренда спецтехники], [Ремонт спецтехники], [Водитель / машинист]. Внутри раздела — вторая тройка кнопок: две роли и [Мои объявления]. Выбранный раздел задаёт вид объявления
```

Строка 128: заменить `Выбор ветки размещения: [Сдаю спецтехнику], [Я мастер] или [Я водитель].` на `Выбор раздела, затем ветки размещения: [Я сдаю спецтехнику], [Я мастер] или [Я водитель].`

Строка 132: заменить `При выборе [Мои объявления] из главного меню` на `При выборе [Мои объявления] на экране любого раздела`.

Строка 136: заменить `Выбор ветки поиска: [Ищу спецтехнику], [Ищу мастера] или [Ищу водителя].` на `Выбор раздела, затем ветки поиска: [Я ищу спецтехнику], [Я ищу мастера] или [Я ищу водителя].`

- [ ] **Step 3: `docs/modules/user-flows.md` — типовой сценарий и пять флоу**

Строка 11: заменить целиком на

```markdown
- **Старт** → приветствие → главное меню — три кнопки разделов: **[Аренда спецтехники]**, **[Ремонт спецтехники]**, **[Водитель / машинист]**. Внутри раздела — вторая тройка кнопок: две роли и **[Мои объявления]**. Повторное обращение идёт сразу к меню, минуя приветствие. Нераспознанный ответ повторяет текущий шаг; распознаются только подписи и номера кнопок текущего экрана.
```

Строка 14: заменить `**[Мои объявления]**` на `**[Мои объявления]** (кнопка есть на экране каждого раздела)`.

Строка 21: `Выбирает [Сдаю спецтехнику].` → `Выбирает [Аренда спецтехники], затем [Я сдаю спецтехнику].`

Строка 29: `Выбирает [Я мастер];` → `Выбирает [Ремонт спецтехники], затем [Я мастер];`

Строка 35: `Выбирает [Я водитель];` → `Выбирает [Водитель / машинист], затем [Я водитель];`

Строка 43: `[Мои объявления] в главном меню — бот присылает` → `[Мои объявления] на экране любого раздела — бот присылает`

Строка 48: `Выбирает ветку поиска — [Ищу спецтехнику], [Ищу мастера] или [Ищу водителя];` → `Выбирает раздел, затем ветку поиска — [Я ищу спецтехнику], [Я ищу мастера] или [Я ищу водителя];`

- [ ] **Step 4: `docs/modules/bot-constructor.md` — описание типового сценария**

Строка 122: заменить предложение

```
Типовой главный диалог ведёт меню-списком из семи строк — шесть веток по видам объявлений и «Мои объявления» — по AI-блоку своего вида на каждую ветку.
```

на

```
Типовой главный диалог ведёт меню в два шага на кнопках: сначала три раздела по видам объявлений, внутри раздела — две роли и «Мои объявления»; на каждую ролевую ветку — AI-блок своего вида.
```

- [ ] **Step 5: `docs/modules/ai-assistant.md` — подписи веток поиска**

Строка 55: заменить `заказчик выбирает его веткой меню («Ищу спецтехнику», «Ищу мастера», «Ищу водителя»)` на `заказчик выбирает его разделом меню и веткой внутри него («Я ищу спецтехнику», «Я ищу мастера», «Я ищу водителя»)`.

- [ ] **Step 6: `docs/changelog.md` — запись и деплой-заметка**

Вставить новый раздел сразу после строки 3 (перед `## 2026-08-12`):

```markdown
## 2026-08-13

- Главное меню бота стало двухшаговым и на кнопках (Модули 1 и 5). Раньше после приветствия приходил интерактивный список из семи строк, свёрнутый под кнопку «Выбрать»: заказчик его не принял. Теперь первый шаг — три reply-кнопки разделов по видам объявлений: «Аренда спецтехники», «Ремонт спецтехники», «Водитель / машинист». Внутри раздела — вторая тройка кнопок: две роли («Я сдаю спецтехнику» / «Я ищу спецтехнику», «Я мастер» / «Я ищу мастера», «Я водитель» / «Я ищу водителя») и «Мои объявления». Четвёртой кнопки на первом шаге быть не может — лимит WhatsApp три кнопки в сообщении, поэтому кабинет уехал на второй шаг и перестал быть пунктом верхнего уровня: чтобы попасть в него, надо открыть любой раздел; ссылка ведёт в общий кабинет без фильтра по виду. Число касаний не выросло: список тоже требовал раскрыть его кнопкой «Выбрать» и затем выбрать строку. Возврат к другому разделу — нажатием кнопки первого шага, оставшейся выше в чате: бот сразу открывает экран этого раздела, три раздела заново не показываются. Отдельной кнопки «Назад» нет — третий слот второго шага занят кабинетом. Набор фраз, которые бот понимает текстом, сузился: совпадение по подписи и по номеру ищется только среди кнопок текущего экрана, поэтому «ищу мастера», набранное на первом шаге, больше не срабатывает, а подписи ролей изменились. Машинные идентификаторы веток сохранены, поэтому кнопки прежней версии меню, висящие в открытых чатах, продолжают вести в свои ветки. Обновлены [technical-specification.md](technical-specification.md), [business-rules.md](business-rules.md), [modules/user-flows.md](modules/user-flows.md), [modules/bot-constructor.md](modules/bot-constructor.md) и [modules/ai-assistant.md](modules/ai-assistant.md).

- Деплой-заметка: `make artisan artisan_args="bot:install-default-scenario --force"` — переустановка перезапишет все три типовых сценария и затрёт ручные правки графа в админке. Диалоги, ждущие на прежнем меню, мягко сбросятся в начало и увидят новое меню; диалоги на AI-шагах не затрагиваются. Если граф на проде правили руками, ручная пересборка в редакторе сценариев не годится: редактор не даёт задать идентификатор кнопки и генерирует его случайно, поэтому висящие в чатах кнопки перестанут работать — сохранить их можно только правкой графа напрямую.
```

- [ ] **Step 7: Проверить, что старые подписи нигде не остались**

```bash
grep -rn "семи строк\|Сдаю спецтехнику\|Ищу спецтехнику\|Ищу мастера\|Ищу водителя" docs/ --include=*.md
```

Ожидаемо: совпадения остаются только в `docs/changelog.md` (исторические записи за 2026-08-12 и раньше — их не правим, changelog фиксирует прошлое) и в `docs/superpowers/specs/` и `docs/superpowers/plans/` (спеки и планы — тоже история). Любое совпадение в `docs/technical-specification.md`, `docs/business-rules.md` или `docs/modules/` — незакрытая правка, вернуться к соответствующему шагу.

- [ ] **Step 8: Коммит**

```bash
git add docs/
git commit -m "Доки: главное меню бота тремя категориями на кнопках"
```

---

## Проверка перед сдачей

- [ ] `make test test_args="--compact --filter=InstallDefaultBotScenario"` — зелёный.
- [ ] `make test test_args="--compact --filter=Bot"` — зелёный.
- [ ] `grep` из Task 2 Step 7 не находит старых подписей вне changelog и `docs/superpowers/`.
- [ ] `git diff --stat 7ae859c..HEAD` показывает изменения только в `app/Console/Commands/InstallDefaultBotScenario.php`, `tests/Feature/InstallDefaultBotScenarioTest.php`, `docs/` — ничего в `app/Services/Bot/`, `app/Services/DereuMessenger.php`, `app/Filament/`.
