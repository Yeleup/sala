# WhatsApp typing indicator перед ответом бота

Дата: 2026-08-12 · Статус: утверждён

## Цель

Перед тем как бот обработает входящее сообщение и ответит, контакт должен видеть
стандартный UX живого оператора: сообщение помечено прочитанным (синие галочки)
и показан индикатор «печатает…». Особенно ценно на медленных AI-шагах
(коллектор анкеты, поиск), где ответ занимает секунды.

## Контракт Dereu (факты)

Сторона Dereu уже реализована (PR #203 `feat/typing-indicators`):

```
POST /platform/companies/{external_id}/messages/{wamid}/read
Authorization: Bearer {DEREU_PLATFORM_KEY}

{ "typing_indicator": true }
```

- Авторизация — **platform key**, не company api_key.
- `typing_indicator: true` = «печатает…» + прочитано; typing без прочтения у Meta
  не существует. Без body или `false` — только пометка прочитанным.
- Индикатор гаснет через ~25 секунд или при отправке ответа.
- Прочтение каскадное (более ранние сообщения диалога тоже), допустимо 30 дней.
- Read-receipt не считается сообщением: не тарифицируется, статусов доставки
  не порождает.
- Ответ **200** `{"success": true}`. Ошибки: `404` `message_not_found`;
  `409` чужая компания / нет WABA; `422` Meta отклонила (напр. невалидный wamid);
  `502` Meta недоступна (Dereu не ретраит).

## Принятые решения

| Вопрос | Решение |
|---|---|
| Когда показывать | **На каждое входящее, которое дойдёт до бот-движка** — один вызов в `ProcessDereuWebhookEvent` перед `BotEngine::handle()`. На быстрых ответах индикатор мигнёт и погаснет с ответом |
| Синие галочки | Приемлемы — неотделимы от typing по контракту Meta, стандартный UX бизнес-бота |
| Где живёт HTTP-вызов | `DereuPlatformClient` — эндпоинт под platform key, company-scoped клиенту (`DereuMessenger`) не принадлежит |
| Надёжность | **Best effort**: сбой индикатора никогда не ломает обработку сообщения — только `Log::warning`. Ретраев нет |
| Точка вызова | В джобе, не в вебхук-контроллере: приёмник обязан отвечать 2xx мгновенно, синхронный HTTP там даёт таймаут → ретрай Dereu → дубли событий |
| Журнал | В `channel_messages` не пишем — read-receipt не сообщение |
| Конфиг | Ничего нового: `platform_key`, `external_id`, `base_url` уже есть |

## Компоненты

- `DereuPlatformClient::markMessageRead(string $externalId, string $wamid, bool $typingIndicator = false): void`
  — `POST /platform/companies/{externalId}/messages/{wamid}/read`, body
  `{"typing_indicator": <bool>}`, `->throw()` при ошибке — как остальные методы клиента.
- `ProcessDereuWebhookEvent`: приватный `showTypingIndicator()`, вызывается после
  существующих гардов (чужая компания, reaction/system, пустой телефон) перед
  `engine->handle()`. Пропуск без вызова, если `DereuCompany::current()` нет или
  `wamid` события пуст. Вызов клиента в `try/catch (Throwable)` → `Log::warning`
  с `event_id`.

## Поток данных

1. Вебхук `message_received` → `ProcessDereuWebhookEvent` (очередь, per-contact lock).
2. Существующие гарды и ранние выходы — без изменений; для отсечённых событий
   (reaction, system, чужая компания) индикатор не шлётся.
3. `showTypingIndicator()`: mark-read + typing best-effort.
4. `BotEngine::handle()` — думает, отвечает; индикатор гаснет при ответе.
5. При ретрае джобы (maxExceptions 3) вызов повторится — безопасно: read
   идемпотентен, typing перепоказывается.

## Обработка ошибок

| Ситуация | Поведение |
|---|---|
| Компания не сконфигурирована / `wamid` пуст | Тихий пропуск, HTTP-вызова нет |
| Любая ошибка Dereu/сети (404/409/422/502, таймаут) | `Log::warning` (event_id + текст), обработка сообщения продолжается |

## Тестирование (TDD)

В существующем `tests/Feature/ProcessDereuWebhookEventTest.php`:

1. С `DereuCompany` (factory) + `Http::fake`: джоба шлёт POST на точный URL
   (`/platform/companies/{external_id}/messages/{wamid}/read`) с body
   `{"typing_indicator": true}` и platform key в заголовке; движок вызван,
   событие processed.
2. Dereu отвечает 502 → движок всё равно вызван, событие processed.
3. Компании нет → `Http::assertNothingSent()` — гарантирует, что существующие
   тесты без компании остаются зелёными.

Метод клиента покрывается этими же feature-тестами с точными ассертами URL/body —
как остальные платформенные методы покрыты тестами своих потребителей;
отдельного тест-файла у `DereuPlatformClient` нет.

Прогон — только `make test test_args="--compact --filter=..."`.

## Документация (после реализации)

1. `docs/modules/whatsapp-integration.md` — поведение: перед ответом бот помечает
   входящее прочитанным и показывает «печатает…»; best-effort; гаснет ~25 сек
   или при ответе.
2. `docs/changelog.md` — запись об изменении поведения.
3. Синхронизировать локальную копию скилла `dereu-integration` из монорепо Dereu
   (`packages/laravel-boost/resources/boost/skills/dereu-integration/`) — в копии
   sala ещё нет §4b про mark-as-read/typing.

## Вне скоупа

- Typing перед проактивными отправками (шаблоны, уведомления) — нет входящего
  `wamid`, по контракту Meta невозможно.
- Отдельный mark-as-read без typing.
- Индикатор в `failed()`-пути джобы (извинение шлётся сразу).
- Изменения на стороне Dereu.
