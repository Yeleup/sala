# Выход в меню отовсюду и живой тон бота — план реализации

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Из любого шага AI-диалога можно выйти в главное меню; завершение веток возвращает в меню; тексты главного диалога переписаны по тон-гайду; AI-вопрос — приоритетный источник уточнений.

**Architecture:** Возврат в меню решается графом типового сценария (рёбра «Продолжить» → `main_menu`), а не движком; выход «В меню» — обычное завершение AI-блока (`AiOutcome::Completed`). Кнопка «В меню» добавляется в каждое ждущее сообщение обоих AI-обработчиков; словесная просьба меню — новое значение `menu` в `user_intent` извлекающих агентов (семантическая классификация, без списков фраз — правило `.ai/project-ai-design`). Тексты — рукописные шаблоны из копирайт-таблицы спеки.

**Tech Stack:** Laravel 13, PHP 8.4, Pest 4. Спека: `docs/superpowers/specs/2026-08-17-menu-exit-everywhere-and-tone-design.md` — копирайт-таблица там обязательна к дословному использованию.

## Global Constraints

- Тесты гонять ТОЛЬКО через `make test test_args="--compact --filter=..."` (в git-worktree — `make test-worktree test_args="..."`). Никогда `php artisan test` напрямую и никогда через `docker exec` — снесёт dev-базу.
- Все PHP/composer-команды — только в контейнерах (`make artisan`, `make composer`); host-PHP отсутствует как опция.
- После правок PHP — `docker exec sala-app-1 vendor/bin/pint --dirty --format agent`.
- WhatsApp-лимиты: ≤3 reply-кнопки, подпись кнопки ≤20 символов, ≤10 строк списка, заголовок строки ≤24 символа.
- Id вариантов опубликованного графа (`kind_rental`, `rent_out`, `my`… — все 12) не переименовывать: кнопки в чатах маршрутизируются по id.
- Никакого фразового хардкодинга интентов: словесные выходы — только через `user_intent` извлекающих агентов. Разрешённое исключение — точное совпадение набранного текста с названием видимой кнопки (существующая конвенция сценария).
- Тексты сообщений — дословно из копирайт-таблицы спеки; отсебятина в формулировках — дефект ревью.
- Бот пишет только по-русски (языковая политика); в промптах — гвард «только по-русски».
- Эмодзи в сообщениях бота не используются.
- Существующие тесты, завязанные на старые строки, обновлять на новые строки из таблицы — не удалять.

---

### Task 1: Возврат веток в меню + «приветствие один раз»

**Files:**
- Modify: `app/Console/Commands/InstallDefaultBotScenario.php` (mainDialogDefinition, customerRequestDefinition)
- Modify: `app/Services/Bot/BotEngine.php` (waitAt, дефолт текста MyListings)
- Test: `tests/Feature/InstallDefaultBotScenarioTest.php`, `tests/Feature/BotEngineTest.php`

**Interfaces:**
- Consumes: текущий граф `mainDialogDefinition()`; `BotEngine::waitAt()`.
- Produces: граф без `after_collect`/`after_search`; рёбра `collect_*|search_*|my_listings --continue--> main_menu`; `waitAt()` проставляет `last_dialog_ended_at = now()`. Задачи 2–4 полагаются на то, что «Completed» AI-блока приводит контакта в `main_menu`.

- [ ] **Step 1: Failing-тесты графа** — в `InstallDefaultBotScenarioTest`: (а) рёбра `continue` всех шести AI-узлов и `my_listings` ведут в `main_menu`; (б) узлов `after_collect`/`after_search` нет; (в) тексты узлов `greeting`, `main_menu`, `menu_rental`, `menu_repair`, `menu_driver`, `my_listings`, `already_decided` равны новым строкам копирайт-таблицы. Прогнать: `make test-worktree test_args="--compact --filter=InstallDefaultBotScenario"` — FAIL.
- [ ] **Step 2: Правка `mainDialogDefinition()`** — удалить узлы `after_collect`, `after_search`; шесть рёбер `['from' => 'collect_*|search_*', 'output' => 'continue', 'to' => 'main_menu']`; добавить `['from' => 'my_listings', 'output' => 'continue', 'to' => 'main_menu']`; вписать новые тексты узлов (дословно из таблицы, включая `already_decided` в `customerRequestDefinition()`). PHPDoc метода дополнить: «завершение любой ветки возвращает в главное меню». Прогнать тесты Step 1 — PASS.
- [ ] **Step 3: Failing-тесты движка** — в `BotEngineTest`: (а) после первого же ждущего шага (контакт получил меню, ничего не завершив) следующий диалог (истёкшая сессия) начинается с `main_menu` без приветствия; (б) завершение AI-блока (фейковый ассистент возвращает Completed) приводит к отправке меню тем же ходом. Существующие тесты про «повторное обращение только после конца диалога» обновить под новое правило. FAIL.
- [ ] **Step 4: Правка `BotEngine`** — в `waitAt()` добавить `$session->last_dialog_ended_at = now();` (PHPDoc: приветствие показывается один раз — любой дошедший до ждущего шага контакт дальше «повторный»); дефолт текста `MyListings` (строка ~261) заменить на новый текст узла `my_listings`. Комментарий класса `BotSession::hasCompletedDialog()` поправить под новую семантику. PASS.
- [ ] **Step 5: Pint + коммит** — `docker exec sala-app-1 vendor/bin/pint --dirty --format agent`; `git add -A && git commit -m "feat(bot): ветки главного диалога возвращают в меню, приветствие показывается один раз"`.

### Task 2: «В меню» повсюду в поиске + словесный выход

**Files:**
- Modify: `app/Enums/UserIntent.php`, `app/Ai/Agents/SearchQueryExtractionAgent.php`, `app/Services/Ai/CustomerSearchAssistant.php`
- Test: `tests/Feature/CustomerSearchAssistantTest.php`

**Interfaces:**
- Consumes: Task 1 (Completed → меню приходит графом; в тестах ассистента это не проверяется — только `AiOutcome`).
- Produces: `UserIntent::MenuRequested = 'menu'`; приватный `CustomerSearchAssistant::matchesMenuButton(InboundMessage $message): bool` (replyId `search_to_menu` ИЛИ набранное «в меню» без регистра/трима); константа `MAX_OFFERED_ROWS = 9`. Task 3 полагается на `matchesMenuButton` и на структуру списков «строки + В меню».

- [ ] **Step 1: Failing-тесты** — в `CustomerSearchAssistantTest`: (а) набранный текст «в меню» в фазах `searching`/`choosing`/`locating` возвращает Completed без запуска поиска; (б) `user_intent = 'menu'` от экстрактора → Completed, транскрипт не пополняется; (в) выдача из 10+ матчей шлёт список из 9 строк объявлений + строка `search_to_menu` «В меню»; (г) список мест содержит строку «В меню»; (д) уточняющий вопрос уходит как `sendButtons` с единственной кнопкой `search_to_menu`; (е) `offerWiderCatalog` шлёт кнопку «В меню» отдельным сообщением до CTA. FAIL.
- [ ] **Step 2: `UserIntent`** — кейс `MenuRequested = 'menu'` с PHPDoc «просит главное меню или другой раздел»; `values()` уже отдаёт его автоматически.
- [ ] **Step 3: Промпт `SearchQueryExtractionAgent`** — в описание `user_intent` добавить `"menu"` («заказчик просит вернуться в главное меню, начать сначала или открыть другой раздел»); из `"abandoned"` убрать «вернуться в меню»; правила тона `clarifying_question` (живой короткий вопрос, на «вы», ТОЛЬКО по-русски даже при казахском входе, без канцелярита, без первого лица в прошедшем времени, можно опереться на уже понятое).
- [ ] **Step 4: `CustomerSearchAssistant`** — `matchesMenuButton()` (заменить точечную проверку в `resume()`, строка 147); обработка `UserIntent::MenuRequested` в `search()` рядом с `Abandoned`: откат транскрипта, `persist`, Completed, БЕЗ сообщения; `offerMatches()`: `take(9)` + строка меню; `sendLocationChoices()`: кандидаты ≤9 + строка меню; все `sendText` ждущих вопросов (`clarify`, пустой текст, нераспознанное голосовое, nudge из `repeatCurrentStep`) → `sendButtons(text, [['id' => self::BUTTON_MENU, 'title' => self::BUTTON_MENU_TITLE]])`; `offerWiderCatalog()`: сначала `sendButtons(текст, [В меню])`, затем прежний prefilled CTA. Тесты Step 1 — PASS; весь файл: `make test-worktree test_args="--compact --filter=CustomerSearchAssistant"`.
- [ ] **Step 5: Pint + коммит** — `feat(search): выход «В меню» с каждого шага поиска, словесная просьба меню`.

### Task 3: Честность выдачи и тексты поиска

**Files:**
- Modify: `app/Services/Ai/CustomerSearchAssistant.php`
- Test: `tests/Feature/CustomerSearchAssistantTest.php`

**Interfaces:**
- Consumes: Task 2 (`matchesMenuButton`, структура списков 9+1).
- Produces: приватные `matchOrdinal(array $ids, InboundMessage $message): ?int` (текст «1»–«9» → элемент массива, приоритет у совпадения названия — вызывать ПОСЛЕ title-матчей) и `pluralVariants(int $count): string» («вариант/варианта/вариантов»). Динамический заголовок выдачи.

- [ ] **Step 1: Failing-тесты** — (а) «2» в фазе `choosing` выбирает вторую строку выдачи (заявка размещается); (б) «1» в `locating` выбирает первое место; (в) тап по `listing:{id}` из `offered` со снятым с публикации объявлением шлёт «Этот вариант уже сняли с публикации. Сейчас поищем свежие.» и перезапускает поиск по сохранённому запросу, НЕ тратя безрезультатную попытку; (г) заголовок выдачи при 3 матчах с известным местом — «Нашлось 3 варианта по запросу «кран» в Шымкент…» (точная строка по спеке), при 1 — «Нашёлся 1 вариант…»; (д) строки поиска равны новым текстам копирайт-таблицы (проверять по 2–3 ключевым: пустая выдача, CTA «до 9», placeRequest). FAIL.
- [ ] **Step 2: Реализация** — `matchOrdinal()` в `matchChoice()`/`matchLocationChoice()` после title-матчей; в `resume()` фазы `choosing` — ветка устаревшей строки (id с префиксом строки ∈ `offered`, объявление не searchable → сообщение + `runSearch` с `state['query']` без инкремента счётчика); `offerMatches()` — динамический заголовок с `pluralVariants()`, подстановкой `subject ?: query` и названием места (когда место передано); замена всех строк поиска на тексты таблицы (включая `searchGreeting`, dead-end, CTA, fallback-вопросы). PASS.
- [ ] **Step 3: Pint + коммит** — `feat(search): порядковый номер, честная устаревшая строка, живые тексты поиска`.

### Task 4: «В меню» и тексты анкеты поставщика

**Files:**
- Modify: `app/Services/Ai/SupplierListingCollector.php`, `app/Ai/Agents/ListingExtractionAgent.php`
- Test: `tests/Feature/SupplierListingCollectorTest.php`

**Interfaces:**
- Consumes: `UserIntent::MenuRequested` из Task 2.
- Produces: константы `BUTTON_MENU = 'collect_to_menu'`, `BUTTON_MENU_TITLE = 'В меню'»; выходной путь «в меню» = `abandon()` с новым текстом «Черновик сохранили — он ждёт в кабинете.» при непустом черновике и молча при пустом.

- [ ] **Step 1: Failing-тесты** — (а) кнопка/набранное «в меню» в фазах `collecting`/`confirming`/`locating`/`choosing` → Completed, черновик сохранён, текст «Черновик сохранили — он ждёт в кабинете.» (при пустом — без сообщения); (б) `user_intent='menu'` → тот же выход; (в) уточняющий вопрос и просьбы повторить уходят `sendButtons` с кнопкой `collect_to_menu`; (г) сводка подтверждения несёт три кнопки: «Да, отправить», «Исправить», «В меню» (в варианте `awaiting_document` — «Исправить» + «В меню»); (д) список мест содержит строку «В меню»; (е) «Исправить» при удалённом черновике шлёт «Этот черновик уже удалён — править нечего. Если нужно, начните заново из меню.»; (ж) тексты из копирайт-таблицы (нечитаемое, сбой AI, submit, рамка подтверждения, CTA «Исправить», handOff). FAIL.
- [ ] **Step 2: Реализация** — метод `exitToMenu(BotSession, array $state): AiOutcome` (клон `abandon()` с текстами меню-выхода); проверка `matchesButton($message, self::BUTTON_MENU, self::BUTTON_MENU_TITLE)` первой строкой `resume()` и обработка `MenuRequested` рядом с `Abandoned` в `handleCollecting`; кнопка меню во всех `sendText`-вопросах (`clarification`, `repeatCurrentStep`, нечитаемое, сбой), третий слот `sendConfirmation`, строка в `sendLocationChoices`; ветка удалённого черновика в `handleConfirmation` (BUTTON_EDIT, `$draft === null`); замена строк по таблице. Промпт `ListingExtractionAgent`: `menu` в `user_intent`, тон `clarifying_question`, гвард «только по-русски» (симметрично Task 2 Step 3). PASS: `make test-worktree test_args="--compact --filter=SupplierListingCollector"`.
- [ ] **Step 3: Pint + коммит** — `feat(collector): выход «В меню» из анкеты, честное «Исправить», живые тексты`.

### Task 5: Встроенные ответы и приоритет AI-вопроса

**Files:**
- Modify: `app/Enums/BotReplyKey.php`, `app/Services/Ai/SupplierListingCollector.php` (clarificationQuestion), `app/Services/Ai/CustomerSearchAssistant.php` (при расхождении приоритета)
- Test: `tests/Feature/BotReplyTextsTest.php`, `tests/Feature/SupplierListingCollectorTest.php`

**Interfaces:**
- Consumes: тексты Task 3–4.
- Produces: новые дефолты четырёх `BotReplyKey`; правило «вопрос модели приоритетнее шаблона» для не-локационных полей в обоих потоках.

- [ ] **Step 1: Failing-тесты** — (а) `BotReplyTextsTest`: четыре дефолта равны новым строкам таблицы; (б) `SupplierListingCollectorTest`: при непустом `clarifying_question` от модели уходит именно он, шаблон `fallbackQuestions` — только при пустом; для отсутствующего места — всегда шаблон. FAIL (пункт б может оказаться уже зелёным — тогда это фиксация поведения).
- [ ] **Step 2: Реализация** — новые строки `BotReplyKey::default()`; выправить приоритет в `clarificationQuestion()` обоих обработчиков, если модельный вопрос сейчас перекрывается шаблоном для не-локационных полей. PASS.
- [ ] **Step 3: Pint + коммит** — `feat(bot): живые встроенные ответы, приоритет AI-вопроса над шаблоном`.

### Task 6: Документация

**Files:**
- Create: `docs/bot-tone-guide.md`
- Modify: `docs/business-rules.md`, `docs/modules/ai-assistant.md`, `docs/modules/bot-constructor.md`, `docs/modules/user-flows.md`, `docs/changelog.md`

**Interfaces:** Consumes: спека (разделы «Тон-гайд», «Инварианты», «Документация»). Produces: только документация, поведение не описывать через имена классов/методов (правило `.ai/project-documentation`: поведение, не реализация).

- [ ] **Step 1: `docs/bot-tone-guide.md`** — восемь правил тон-гайда из спеки, без имён классов.
- [ ] **Step 2: Обновления** — пять инвариантов в `business-rules.md` (+ правка «Повторного обращения»); `ai-assistant.md` — выход «В меню», intent `menu`, выдача 9+1, порядковый номер, устаревшая строка; `bot-constructor.md` — возврат веток в меню, приветствие один раз, типовой сценарий без тупиковых узлов; `user-flows.md` — шаги выхода; `changelog.md` — запись + деплой-заметка «`bot:install-default-scenario --force` перезапишет все три типовых сценария».
- [ ] **Step 3: Коммит** — `docs: тон-гайд и правила выхода в меню`.

### Task 7: Полный прогон и публикация сценария в dev

- [ ] **Step 1:** `make test-worktree test_args="--compact"` — полный прогон, всё зелёное (19 давних env-фейлов dereu-сьюта — преждесуществующие, к этой ветке не относятся).
- [ ] **Step 2:** Pint по ветке: `docker exec sala-app-1 vendor/bin/pint --dirty --format agent`.
- [ ] **Step 3:** После мерджа в master (не в worktree): `make artisan artisan_args="bot:install-default-scenario --force"` — публикация обновлённого графа в dev-базу. В worktree этот шаг НЕ выполнять.
