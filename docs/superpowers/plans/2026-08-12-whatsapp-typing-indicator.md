# WhatsApp Typing Indicator Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Перед тем как бот обработает входящее WhatsApp-сообщение, пометить его прочитанным и показать контакту индикатор «печатает…» (best effort, через платформенный API Dereu).

**Architecture:** Один новый метод `markMessageRead()` в `DereuPlatformClient` (platform key) и один best-effort вызов в `ProcessDereuWebhookEvent` после существующих гардов, перед `BotEngine::handle()`. Сбой вызова логируется warning'ом и не мешает обработке. Спека: `docs/superpowers/specs/2026-08-12-whatsapp-typing-indicator-design.md`.

**Tech Stack:** Laravel 13, Pest 4, Laravel HTTP client (`Http::fake` в тестах). Всё исполняется в Docker.

## Global Constraints

- Никакого PHP на хосте: тесты ТОЛЬКО `make test test_args="..."`; Pint — `docker exec sala-app-1 vendor/bin/pint --dirty --format agent`.
- `php artisan test` напрямую (в т.ч. через `docker exec`) ЗАПРЕЩЁН — снесёт dev-БД.
- Эндпоинт Dereu: `POST /platform/companies/{external_id}/messages/{wamid}/read`, заголовок `Authorization: Bearer {platform_key}`, body `{"typing_indicator": true}` → `200 {"success": true}`. Сторона Dereu уже реализована — в этом проекте только клиентская часть.
- Хелперы тестов уже существуют: `connectedDereuCompany(['...'])` (tests/Pest.php:51, external_id = `org_test`), `inboundMessageEvent()` / `runDereuWebhookJob()` (tests/Feature/ProcessDereuWebhookEventTest.php:13-29).
- Guard джобы сравнивает `company_id` события с `dereu_company_id` компании — в тестах их надо выравнивать явно (`co_ours`).
- Коммиты на русском, в конце каждого: `Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>`.
- Документация описывает поведение, не реализацию (никаких имён классов/методов в docs/).

---

### Task 1: `markMessageRead` в платформенном клиенте + вызов в джобе (TDD)

**Files:**
- Modify: `app/Services/DereuPlatformClient.php` (после `syncTemplates`, перед `request()`)
- Modify: `app/Jobs/ProcessDereuWebhookEvent.php` (сигнатура `handle`, вызов перед `$engine->handle(...)`, новый приватный метод)
- Test: `tests/Feature/ProcessDereuWebhookEventTest.php`

**Interfaces:**
- Consumes: `DereuPlatformClient::request()` (уже есть — platform key, baseUrl, throw при blank key); `DereuCompany::current()`; `$event->wamid`, `$event->event_id`.
- Produces: `DereuPlatformClient::markMessageRead(string $externalId, string $wamid, bool $typingIndicator = false): void` — бросает `Illuminate\Http\Client\RequestException` на не-2xx и `RuntimeException` при пустом platform key.

- [ ] **Step 1: Написать тесты (3 новых + правка одного существующего)**

В `tests/Feature/ProcessDereuWebhookEventTest.php` добавить импорты к существующим `use`:

```php
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
```

Добавить три теста в конец файла:

```php
test('an inbound message is marked read with a typing indicator before the engine replies', function () {
    config()->set('services.dereu.external_id', 'org_test');
    config()->set('services.dereu.platform_key', 'plat_test.secret');
    config()->set('services.dereu.base_url', 'https://api.dereu.test/api/v1');
    connectedDereuCompany(['dereu_company_id' => 'co_ours']);
    test()->mock(BotEngine::class)->shouldReceive('handle')->once();
    Http::fake(['api.dereu.test/*' => Http::response(['success' => true])]);

    $event = inboundMessageEvent(overrides: ['company_id' => 'co_ours']);

    runDereuWebhookJob($event);

    Http::assertSent(fn (Request $request) => $request->url() === 'https://api.dereu.test/api/v1/platform/companies/org_test/messages/'.$event->wamid.'/read'
        && $request->hasHeader('Authorization', 'Bearer plat_test.secret')
        && $request['typing_indicator'] === true);
    expect($event->fresh()->processed_at)->not->toBeNull();
});

test('a failed read receipt does not cost the message its processing', function () {
    config()->set('services.dereu.external_id', 'org_test');
    config()->set('services.dereu.platform_key', 'plat_test.secret');
    config()->set('services.dereu.base_url', 'https://api.dereu.test/api/v1');
    connectedDereuCompany(['dereu_company_id' => 'co_ours']);
    test()->mock(BotEngine::class)->shouldReceive('handle')->once();
    Http::fake(['api.dereu.test/*' => Http::response(['error' => 'meta_unavailable'], 502)]);

    $event = inboundMessageEvent(overrides: ['company_id' => 'co_ours']);

    runDereuWebhookJob($event);

    expect($event->fresh()->processed_at)->not->toBeNull();
});

test('no read receipt is attempted when no Dereu company is connected', function () {
    test()->mock(BotEngine::class)->shouldReceive('handle')->once();
    Http::fake();

    runDereuWebhookJob(inboundMessageEvent());

    Http::assertNothingSent();
});
```

В существующем тесте `'an event of our own company is processed'` (~строка 152) после строки с mock добавить `Http::fake();` — после реализации джоба начнёт слать HTTP, а этот тест не про read-receipt:

```php
test('an event of our own company is processed', function () {
    config()->set('services.dereu.external_id', 'org_test');
    connectedDereuCompany(['dereu_company_id' => 'co_ours']);
    test()->mock(BotEngine::class)->shouldReceive('handle')->once();
    Http::fake();

    runDereuWebhookJob(inboundMessageEvent(overrides: ['company_id' => 'co_ours']));

    expect(Contact::count())->toBe(1);
});
```

- [ ] **Step 2: Прогнать тесты — первый должен упасть**

Run: `make test test_args="--compact --filter=ProcessDereuWebhookEventTest"`

Expected: тест `an inbound message is marked read...` FAIL на `Http::assertSent` («An expected request was not recorded»). Тесты про 502 и про отсутствие компании пройдут и до реализации — это guard-тесты, их ценность в защите от регрессий после неё. Остальные тесты файла зелёные.

- [ ] **Step 3: Реализовать метод клиента**

В `app/Services/DereuPlatformClient.php` после `syncTemplates()`:

```php
/**
 * Mark an inbound message read and optionally show the «typing…»
 * indicator — the live-operator cue while the bot prepares a reply.
 * A read receipt is not a message: nothing is billed and no delivery
 * status events come back for it.
 */
public function markMessageRead(string $externalId, string $wamid, bool $typingIndicator = false): void
{
    $this->request()
        ->post("/platform/companies/{$externalId}/messages/{$wamid}/read", [
            'typing_indicator' => $typingIndicator,
        ])
        ->throw();
}
```

- [ ] **Step 4: Встроить вызов в джобу**

В `app/Jobs/ProcessDereuWebhookEvent.php`:

Добавить импорт: `use App\Services\DereuPlatformClient;`

Расширить сигнатуру `handle` (метод-инъекция, как уже сделано с `BotEngine`):

```php
public function handle(BotEngine $engine, DereuPlatformClient $platform): void
```

Перед строкой `$engine->handle($contact, InboundMessage::fromWebhookEvent($event));` вставить:

```php
$this->showTypingIndicator($platform, $event);
```

После метода `journalInbound()` добавить:

```php
/**
 * Best effort: mark the inbound message read and show «typing…» while
 * the engine prepares the reply. The indicator dies with the reply (or
 * on its own in ~25 seconds), and a failure here must never cost the
 * message its processing — the receipt is UX, the reply is the job.
 */
private function showTypingIndicator(DereuPlatformClient $platform, DereuWebhookEvent $event): void
{
    $company = DereuCompany::current();

    if ($company === null || blank($event->wamid)) {
        return;
    }

    try {
        $platform->markMessageRead($company->external_id, $event->wamid, typingIndicator: true);
    } catch (Throwable $e) {
        Log::warning('Failed to mark an inbound message read / show typing.', [
            'event_id' => $event->event_id,
            'error' => $e->getMessage(),
        ]);
    }
}
```

- [ ] **Step 5: Прогнать тесты джобы и соседей, задетых новым HTTP-вызовом**

Run: `make test test_args="--compact --filter=ProcessDereuWebhookEventTest"`
Expected: PASS (все, включая три новых).

Run: `make test test_args="--compact --filter=ChannelMessageJournalTest"`
Expected: PASS — его тесты «входящие в журнале» гоняют джобу без строки `DereuCompany` в БД, поэтому read-receipt тихо пропускается; проверяем, что это так.

Run: `make test test_args="--compact --filter=RedispatchUnprocessedDereuEventsTest"`
Expected: PASS (джобы там только ставятся в очередь, не исполняются).

- [ ] **Step 6: Pint**

Run: `docker exec sala-app-1 vendor/bin/pint --dirty --format agent`
Expected: без замечаний (или автопочинка стиля — перепрогнать тесты из Step 5, если что-то поменял).

- [ ] **Step 7: Commit**

```bash
git add app/Services/DereuPlatformClient.php app/Jobs/ProcessDereuWebhookEvent.php tests/Feature/ProcessDereuWebhookEventTest.php
git commit -m "$(cat <<'EOF'
Бот помечает входящее прочитанным и показывает «печатает…» перед ответом

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 2: Документация поведения

**Files:**
- Modify: `docs/modules/whatsapp-integration.md` (раздел «Приём входящих сообщений», после пункта про последовательную обработку и чужие компании — строка ~43)
- Modify: `docs/changelog.md` (пункт в существующую секцию `## 2026-08-12`)

**Interfaces:**
- Consumes: поведение из Task 1 (описываем только его, без имён классов/методов).
- Produces: ничего для последующих задач.

- [ ] **Step 1: Пункт в модульную доку**

В `docs/modules/whatsapp-integration.md`, в списке раздела «Приём входящих сообщений», после пункта «Сообщения одного контакта обрабатываются строго последовательно; события чужой компании …» вставить:

```markdown
- Перед обработкой сообщения ботом входящее помечается прочитанным (контакт видит синие галочки) и включается индикатор «печатает…» — видно, что ответ готовится; особенно заметно на AI-шагах, где ответ занимает секунды. Прочтение и индикатор неразделимы (ограничение WhatsApp: «печатает» без прочтения не бывает), прочтение каскадное — затрагивает и более ранние сообщения диалога. Индикатор гаснет при отправке ответа или сам через ~25 секунд. Пометка — best effort: её сбой не мешает обработке сообщения и ответу. События, которые бот не обрабатывает (реакции, системные, чужая компания), прочитанными не помечаются.
```

- [ ] **Step 2: Пункт в changelog**

В `docs/changelog.md`, в конец существующей секции `## 2026-08-12` (после пункта про деплой-заметки), добавить:

```markdown
- Бот перестал молчать в момент раздумий (Модуль 3). Раньше между сообщением клиента и ответом бота — на AI-шагах это несколько секунд — канал выглядел мёртвым: ни прочтения, ни признаков жизни. Теперь перед обработкой входящее помечается прочитанным (синие галочки), и контакт видит индикатор «печатает…» — стандартный UX живого оператора. Прочтение и индикатор неразделимы — ограничение WhatsApp; индикатор гаснет с ответом или сам через ~25 секунд. Пометка — best effort: её сбой не мешает ответу. Реакции, системные события и события чужой компании прочитанными не помечаются. Обновлён [modules/whatsapp-integration.md](modules/whatsapp-integration.md).
```

- [ ] **Step 3: Commit**

```bash
git add docs/modules/whatsapp-integration.md docs/changelog.md
git commit -m "$(cat <<'EOF'
Доки: прочтение и «печатает…» перед ответом бота

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 3: Синхронизировать локальную копию скилла dereu-integration

Локальная копия скилла отстала от источника истины в монорепо Dereu: в ней нет §4b (mark-as-read / typing) и свежих уточнений про перенос номера. `.claude/skills/dereu-integration` — симлинк на `.ai/skills/dereu-integration`, копировать нужно только в `.ai/`.

**Files:**
- Modify: `.ai/skills/dereu-integration/SKILL.md` (полная замена содержимого из монорепо)
- Modify: `.ai/skills/dereu-integration/examples/DereuConnect.php` (полная замена)

**Interfaces:**
- Consumes: источник истины `/home/magzhan9292/PhpstormProjects/Projects/noticeup/dereu/packages/laravel-boost/resources/boost/skills/dereu-integration/`.
- Produces: ничего для кода.

- [ ] **Step 1: Скопировать файлы из монорепо**

```bash
cp /home/magzhan9292/PhpstormProjects/Projects/noticeup/dereu/packages/laravel-boost/resources/boost/skills/dereu-integration/SKILL.md /home/magzhan9292/PhpstormProjects/Projects/noticeup/sala/.ai/skills/dereu-integration/SKILL.md
cp /home/magzhan9292/PhpstormProjects/Projects/noticeup/dereu/packages/laravel-boost/resources/boost/skills/dereu-integration/examples/DereuConnect.php /home/magzhan9292/PhpstormProjects/Projects/noticeup/sala/.ai/skills/dereu-integration/examples/DereuConnect.php
```

- [ ] **Step 2: Проверить дифф**

Run: `git diff --stat .ai/skills/dereu-integration/ && git diff .ai/skills/dereu-integration/SKILL.md | grep -c "^+"`
Expected: в диффе появились секция `## 4b. Прочитано + «печатает…» (mark-as-read / typing)`, триггеры «typing indicator» в description и уточнения про перенос номера. Ничего проектно-специфичного sala не удалено (файл — чистая копия скилла, локальных правок в нём быть не должно; если дифф показывает удаление чего-то не из монорепо — остановиться и показать пользователю).

- [ ] **Step 3: Commit**

```bash
git add .ai/skills/dereu-integration
git commit -m "$(cat <<'EOF'
update dereu skills

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>
EOF
)"
```
