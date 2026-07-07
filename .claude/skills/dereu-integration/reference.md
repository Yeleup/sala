# Интеграция с Dereu — гайд для партнёрского проекта

Этот документ — контракт для разработчиков **партнёрского SaaS-проекта** (например, satu-plus.kz),
подключающегося к Dereu как к своему WhatsApp-хабу. Dereu — официальный Meta Tech Provider:
сам создаёт/хранит WABA клиентов, принимает единый webhook от Meta и роутит события по
`phone_number_id → компания партнёра`.

Полная машиночитаемая спецификация (эндпоинты `api.key`/`sanctum`, схемы, примеры): `services/api/public/openapi.yaml`
(отдаётся также на `/docs` API-сервиса). Этот гайд — навигация по ней с фокусом на партнёрский (M2M) сценарий.

## 1. Модель

```
Партнёр (ваш проект, напр. satu-plus)
  └─ Компания (Dereu company, 1:1 с номером WhatsApp)
       └─ Номер (phone_number_id)
```

- **Партнёр** на машинном уровне — это **`platform_credentials`**: один M2M-ключ на весь ваш проект,
  не привязанный к конкретной компании.
- **Компания** — тенант в Dereu, соответствует вашей организации/клиенту (`external_id` — ваш internal id).
  Строго 1 организация = 1 company = 1 номер.
- Dereu берёт на себя: приём и хранение WABA/System User Token, приём вебхуков от Meta, форвард их вам,
  постановку исходящих в очередь и отправку через Cloud API с учётом лимитов.
- На вашей стороне остаётся: бизнес-логика вашего продукта, UI Embedded Signup (наш `app_id`/`config_id`,
  запускаете у себя во фронтенде), приём форвардов на свой webhook-эндпоинт.

## 2. Аутентификация

Два независимых механизма — не путайте их:

| Ключ | Формат заголовка | Кем выдаётся | Область действия |
|---|---|---|---|
| **Platform key** (M2M) | `Authorization: Bearer plat_<prefix>.<secret>` | Оператор Dereu (`dereu:issue-platform-key`) | Провижининг/депровижининг компаний, онбординг WABA, каталоги — **не привязан к компании** |
| **API-ключ компании** | `Authorization: Bearer dereu_...` | Выдаётся автоматически при провижининге компании (в ответе `POST /platform/companies`) | Отправка сообщений, OTP, opt-in — **привязан к конкретной компании** |

Platform key выдаётся один раз, отображается только при создании — храните в секрет-хранилище.
При выпуске платформенного ключа Dereu также настраивает **общий форвард** — URL и secret для приёма вебхуков
(см. §5) — единые на весь ваш проект, отдельно для каждой компании настраивать не нужно.

Получить platform key: обратитесь к оператору Dereu — он выполнит
`php artisan dereu:issue-platform-key --name=<ваш проект> --webhook-url=https://...` и передаст вам ключ + webhook secret.

## 3. Провижининг компании

`POST /platform/companies` — создание/получение тенанта. Идемпотентно по `external_id`.

**Запрос:**

```bash
curl -X POST https://api.dereu.noderail.io/api/v1/platform/companies \
  -H "Authorization: Bearer plat_xxxx.yyyy" \
  -H "Content-Type: application/json" \
  -d '{
    "external_id": "org_123",
    "name": "ООО Ромашка"
  }'
```

| Поле | Обязательное | Описание |
|---|---|---|
| `external_id` | да | Ваш внутренний id организации/тенанта |
| `name` | нет | Если не передано — используется `external_id` |
| `phone_number_id` | нет | Meta phone_number_id — если передан, сразу регистрируется маршрут входящих (без System User Token, т.е. без возможности отправки, только приём/форвард) |
| `waba_id` | нет | Meta WABA id (если не передан — используется `phone_number_id`) |
| `display_phone_number` | нет | Человекочитаемый номер, для вашего контракта (сейчас не персистится отдельным полем) |

**Ответ 201 (первое создание):**

```json
{
  "dereu_company_id": "co_abc123",
  "api_key": "dereu_xxxxxxxxxxxx",
  "phone_number_id_registered": true
}
```

`api_key` показывается **только один раз** — сохраните сразу. `phone_number_id_registered` присутствует,
только если в запросе передавался `phone_number_id`.

**Ответ 200 (повтор с тем же `external_id`):**

```json
{ "dereu_company_id": "co_abc123", "already_provisioned": true }
```

`api_key` повторно не выдаётся — если потеряли, обратитесь к оператору Dereu (или создайте новый через
`/api-keys` человеком-владельцем компании, войдя в её ЛК).

**Ошибки:** `401` — неверный platform key; `409` — `phone_number_id` уже зарегистрирован за другой компанией
(номер не переклеивается на новую); `422` — ошибка валидации.

## 4. Онбординг номера (Embedded Signup, Model B)

Вы запускаете Meta Embedded Signup **у себя во фронтенде** с нашими `app_id`/`config_id` (выдаются вместе
с platform key). После завершения попапа Meta присылает вам `code`, `waba_id`, `phone_number_id` — вы
пересылаете их нам, мы обмениваем `code` на System User Token и регистрируем номер в Cloud API.

`POST /platform/companies/{external_id}/waba`

```bash
curl -X POST https://api.dereu.noderail.io/api/v1/platform/companies/org_123/waba \
  -H "Authorization: Bearer plat_xxxx.yyyy" \
  -H "Content-Type: application/json" \
  -d '{
    "code": "AQD...",
    "waba_id": "9876543210",
    "phone_number_id": "1234567890",
    "display_phone_number": "77001234567"
  }'
```

| Поле | Обязательное | Описание |
|---|---|---|
| `code` | да, если нет `system_user_token` | OAuth authorization code из postMessage попапа Embedded Signup |
| `system_user_token` | да, если нет `code` | Существующий System User Token — миграция без Embedded Signup (см. ниже) |
| `waba_id` | да | Meta WABA id |
| `phone_number_id` | да | Meta phone_number_id |
| `display_phone_number` | нет | Человекочитаемый номер |
| `account_mode` | нет | `business_only` (по умолчанию) \| `coexistence` — см. ниже. Также принимает Meta-native значения из Embedded Signup/`MetaWhatsappSettings`: `cloud_api` → `business_only`, `smb_coexistence` → `coexistence` (маппинг идемпотентен для канонических значений) — можно слать своё нативное поле как есть, приводить вручную не нужно |

**Ответ 201:**

```json
{
  "dereu_company_id": "co_abc123",
  "phone_number_id": "1234567890",
  "status": "connected",
  "catalogs": {
    "owned": [{ "id": "CATALOG_1", "name": "Основной каталог" }],
    "waba": [{ "id": "CATALOG_2", "name": "WABA-каталог" }]
  }
}
```

При подключении через `code` в этом же ответе сразу приходят доступные каталоги товаров (Meta Commerce
Catalog) свежеполученным токеном — `owned` (business-каталоги владельца WABA) и `waba` (каталоги,
привязанные к самой WABA) — выбирайте каталог в визарде онбординга сразу по этому ответу, без отдельного
`GET .../catalogs` (см. §7). Токен наружу не отдаётся никогда — только сами каталоги. Если запрос каталогов
к Meta не удался, WABA всё равно считается подключённой (`status: "connected"`), а `catalogs` приходит
пустым (`{"owned": [], "waba": []}`) — в этом случае доберите список отдельным `GET .../catalogs`. Для
`system_user_token` (без `code`) каталоги не запрашиваются — `catalogs` также пустой.

⚠️ **Coexistence/SMB-аккаунты.** Для номеров, подключённых как `coexistence` (SMB business type в терминах
Meta), waba-level запрос `GET /{waba_id}/product_catalogs` штатно отклоняется Meta с ошибкой
`#10 "This operation can not be performed on SMB business type"`. `owned` и `waba` в ответе `POST .../waba`
запрашиваются и деградируют **независимо**: падение одного источника обнуляет только его, второй возвращается
как есть. Поэтому для coexistence/SMB `waba` в ответе будет пустым (`[]`) из-за `#10`, а `owned` (business-каталоги
владельца WABA) приходит непустым, если Meta их отдаёт — выбирайте каталог для coexistence-номеров именно из
`owned`. Отдельный `GET .../catalogs` (см. §7) отдаёт только waba-level список и по-прежнему упадёт с той же
ошибкой Meta для coexistence/SMB — для таких номеров каталог доступен только через `owned` из ответа
`POST .../waba`, отдельный эндпоинт не используйте.

**Ошибки:**

- `409` — `external_id` не принадлежит вашему partner key, либо `phone_number_id` уже занят другой компанией
  (партнёрская изоляция, не ретраится тем же запросом).
- `422` «Код Meta невалиден или уже использован» — `code` из Embedded Signup одноразовый и/или истёк; повтор
  тем же `code` всегда провалится — нужен свежий `code` (переоткройте попап Embedded Signup).
- `502` «Meta временно недоступна» — временный сбой/таймаут на стороне Meta при обмене `code` или регистрации
  номера; ретраебельно, но так как `code` одноразовый — повторяйте со свежим `code`, не тем же самым.
- `422` «Meta отклонила регистрацию номера в Cloud API» — Meta вернула ошибку на `/register`/`/subscribed_apps`,
  например `#133005 Two-step verification PIN Mismatch` (у номера уже стоит 2FA-PIN на стороне Meta) — повтор
  с тем же PIN/токеном не поможет, нужно снять PIN в Meta Business Manager или использовать другой номер.

**Coexistence (WhatsApp Business App) — поддерживается.** Передайте `account_mode: "coexistence"`, если
номер продолжает работать в обычном приложении WhatsApp Business App на телефоне клиента параллельно с
Cloud API — тогда шаг регистрации номера (`/register` + PIN) пропускается, так как номер уже зарегистрирован
и активен в приложении (PIN не генерируется и не сохраняется для такого номера). Без поля (или
`account_mode: "business_only"`) — обычный путь с полной регистрацией через `/register`.

**Миграция существующего токена.** Вместо `code` можно передать `system_user_token` — уже имеющийся у вас
System User Token существующего номера (миграция без нового прохода Embedded Signup): ровно одно из полей
`code`/`system_user_token` обязательно. Обмен с Meta OAuth в этом случае не выполняется — токен принимается
как есть и сразу шифруется на нашей стороне. **`/register` и `/subscribed_apps` в этом пути не вызываются**:
переданный токен выпущен под ВАШИМ Meta-app, а не под нашим, и вызов `/subscribed_apps` этим токеном
переподписал бы WABA на ваш app — Meta начала бы слать вебхуки с чужой подписью, и наш webhook-receiver
их отклонил бы. Подписка на наш app должна быть установлена заранее (на этапе онбординга номера);
`system_user_token` используется только для отправки исходящих сообщений.

## 5. Приём входящих (webhook-форвард)

При выпуске вашего platform key оператор Dereu настраивает **один общий webhook URL и один общий secret
на весь ваш проект** — компании, провижиненные под этим ключом, автоматически получают этот форвард,
отдельный `POST /webhooks` на каждую компанию не нужен (тенанты различаются полем `company_id` в теле события).

**Подпись запроса:** заголовок `X-Dereu-Signature: sha256=<hex>`, где
`hex = HMAC-SHA256(ваш webhook_secret, raw_body)`. Проверяйте до парсинга JSON.

**Формат события** — `type`/`payload` являются **pass-through сырого объекта Meta**: `type` буквально
`messages[].type` из вебхука Meta, `payload` — буквально `messages[].<type>`, без разбора на известные поля.
Так контент не теряется даже для новых/неизвестных типов Meta.

```json
{
  "event": "message_received",
  "event_id": "01J8Z...",
  "company_id": "co_abc123",
  "phone_number_id": "1234567890",
  "from": "77011234567",
  "wamid": "wamid.HBg...",
  "type": "text",
  "text": "Привет",
  "payload": { "body": "Привет" },
  "timestamp": 1718000000
}
```

- `event_id` — ULID, уникален для **конкретной доставки** события (для дедупа доставок; один `wamid`
  может породить несколько статусных событий sent/delivered/read, у каждого свой `event_id`).
- `wamid` — ключ идемпотентности **сообщения** у Meta (для входящих используйте его, а не `event_id`,
  чтобы схлопывать повторные доставки одного и того же сообщения).
- `type` — известные значения: `text`, `image`, `video`, `audio`, `document`, `sticker`, `location`,
  `interactive`, `button`, `order`, `contacts`, `reaction`, `system`, `unsupported` — список не закрыт.
- Статусы доставки исходящих: `message_sent`, `message_delivered`, `message_read`, `message_failed` —
  несут `message_id` (id из ответа `/messages/send`) и `wamid`.

Примеры `payload` по типам — см. `services/api/public/openapi.yaml` (раздел `message_received` в description).

**Требования к вашему эндпоинту:** отвечайте `2xx` быстро. Доставка ретраится (несколько попыток с backoff);
при исчерпании попыток событие уходит во внутренний dead-letter Dereu — не теряется, но не долетает до вас
автоматически повторно; в таком случае обращайтесь к оператору Dereu.

**Coexistence-события.** webhook-receiver распознаёт `field` вебхука Meta `smb_message_echoes` (эхо
сообщения, отправленного из приложения WhatsApp Business App на телефоне, а не от клиента) и
`smb_app_state_sync` (синхронизация контактов из приложения) как отдельные типы события —
`business_app_message_echo` и `business_app_contact_sync` соответственно, с тем же pass-through сырого
payload. ⚠️ На момент написания это разделение сделано только в webhook-receiver (постановка в очередь
`inbound:events`); обработчик очереди на стороне Laravel эти два типа пока не разбирает и **не форвардит
их вам** — событие поглощается без публикации. Если вам нужны эти события уже сейчас, сообщите оператору
Dereu.

## 6. Отправка сообщений

`POST /messages/send` — авторизация **API-ключом компании** (`dereu_...`), не platform key.
`company_id` берётся из ключа, `phone_number_id` — из тела.

```bash
curl -X POST https://api.dereu.noderail.io/api/v1/messages/send \
  -H "Authorization: Bearer dereu_xxxxxxxxxxxx" \
  -H "Content-Type: application/json" \
  -d '{
    "phone_number_id": "1234567890",
    "to": "+77010000000",
    "type": "text",
    "payload": { "body": "Привет" }
  }'
```

Поддерживаемые `type`: `text`, `template`, `otp`, `image`, `video`, `document`, `audio`, `interactive`,
`location`, `contacts`. `payload` — pass-through в Meta Cloud API (кладётся под ключом `type`), обязательный
минимум по типам:

| type | Обязательные поля payload |
|---|---|
| `text` | `{ body }` |
| `image` / `video` / `audio` | `id` **или** `link` (+опц. `caption`) |
| `document` | `id` **или** `link` + `filename` |
| `interactive` | `{ type, body?, action }` |
| `location` | `{ latitude, longitude, name?, address? }` |
| `contacts` | непустой массив контактов верхнего уровня (не объект) |

`payload.id` и `payload.link` для медиа-типов — взаимоисключающие, обязательно ровно одно из двух.
Если у вас уже есть публичный URL файла — передавайте `payload.link` напрямую, отдельная загрузка через
`POST /media` не нужна: Meta сама скачает файл по ссылке. `payload.id` (media_id, см. §7) нужен только
для локальных файлов без публичного URL, которые сначала загружаются через `POST /media`.

`marketing: true` требует предварительного opt-in получателя (см. `POST /optin`, `403 opt_in_required` иначе).
`context: { message_id: "<wamid>" }` — reply-threading, зеркалирует Meta дословно.

**Ответ 202:** `{ "id": "<uuid>", "status": "queued" }`.

**Ошибки:** `401` — неверный/отсутствующий ключ; `403` — `{status:"failed", reason:"suspended"}` (компания
приостановлена) или `{status:"failed", reason:"opt_in_required"}`; `422` — валидация; `429` — превышен лимит
запросов (throttle `60/мин` на маршрут `/messages/send` на компанию; отдельно Meta применяет собственные
per-номер лимиты на стороне Cloud API — их превышение видно в статусе `message_failed`, а не сразу в ответе на send).

OTP — отдельный трек: `POST /otp/send { phone_number_id, to }` → `202`, код живёт 5 минут;
`POST /otp/verify { to, code }` → `200 {status: verified}` / `422` неверный код / `429` превышен лимит попыток.

Opt-in: `POST /optin { phone, type: marketing|transactional, source, source_detail?, consent_text }` → `201`.

## 7. Шаблоны, медиа, каталоги

- **Шаблоны** — `GET/POST /templates`, `DELETE /templates/{id}`, `POST /templates/sync`
  (человеческий ЛК, Sanctum, не platform key) — управление шаблонами компании доступно только через
  веб-интерфейс/сессию владельца компании на сегодня; машинного эндпоинта под platform key нет.
  `sync` тянет актуальный список шаблонов и статусы напрямую из Meta Graph API и upsert'ит их локально
  (идемпотентно) — полезно, если статус изменился, а webhook `message_template_status_update` не дошёл.
- **Каталоги** — `GET /platform/companies/{external_id}/catalogs` (platform key) — список каталогов компании,
  привязанных к WABA. Обычно не нужен отдельно: тот же список (плюс owned-каталоги business-аккаунта) уже
  приходит синхронно в ответе `POST .../waba` при подключении через `code` (см. §4) — используйте этот
  эндпоинт, только если нужно перечитать каталоги позже (токен уже сохранён у нас) или их состав изменился.
  ⚠️ Для coexistence/SMB-номеров этот эндпоинт возвращает только waba-level список и падает с ошибкой Meta
  `#10 "This operation can not be performed on SMB business type"` — см. нюанс coexistence/SMB в §4.
- **Товары каталога** — `GET /platform/companies/{external_id}/catalogs/{catalog_id}/products` — все товары
  каталога (прокси к Graph API, сервер сам проходит по `paging.next` — в ответе сразу полный список, без
  курсоров на вашей стороне). `catalog_id` обязан входить в список из `GET .../catalogs` этой же
  компании — чужой/несуществующий `catalog_id` → `409`.
- **Product feeds каталога** — `GET /platform/companies/{external_id}/catalogs/{catalog_id}/feeds` —
  список feed'ов каталога (та же изоляция по `catalog_id`, что и у товаров).
- **Создание feed'а** — `POST /platform/companies/{external_id}/catalogs/{catalog_id}/feeds`
  `{name, schedule?: {url, interval: HOURLY|DAILY|WEEKLY, hour?, day?}}` → `201 {id}` — прокси к Graph API
  `POST /{catalog_id}/product_feeds`, та же изоляция `catalog_id`, что и у списка. Загрузка товаров в
  созданный feed (upload URL / scheduled fetch) — вне скоупа, обсудите с оператором Dereu при необходимости.
- **Медиа** — `POST /media` (API-ключ компании) — загружает файл в Cloud API токеном компании и
  возвращает `{media_id, mime_type}`; используйте `media_id` в `payload.id` при `POST /messages/send`
  (image/video/audio/document). Лимиты размера — по типу файла (как у WhatsApp Cloud API: image 5MB,
  video/audio 16MB, document 100MB, sticker 500KB). Нужен только для файлов без публичного URL — если файл
  уже доступен по HTTPS-ссылке, загрузка не требуется: передайте `payload.link` прямо в `/messages/send`
  (см. §6), Meta скачает файл сама.
- **Скачивание медиа** — `GET /media/{media_id}` (API-ключ компании) — проксирует байты входящего
  медиа-сообщения (`image`/`video`/`document`/…) с корректным Content-Type. System User Token компании
  партнёру не передаётся — Dereu резолвит его сам и скачивает файл через Graph API `GET /{media-id}`.
  `media_id` должен принадлежать WABA компании-вызывателя — чужой вернёт 403, неизвестный — 404.
- **Business Profile** — `GET`/`PATCH` `/platform/companies/{external_id}/profile` (platform key) —
  просмотр и обновление WhatsApp Business Profile номера (about/address/description/email/websites/vertical).

  ```bash
  curl "https://api.dereu.noderail.io/api/v1/platform/companies/org_123/profile" \
    -H "Authorization: Bearer plat_xxxx.yyyy"

  curl -X PATCH "https://api.dereu.noderail.io/api/v1/platform/companies/org_123/profile" \
    -H "Authorization: Bearer plat_xxxx.yyyy" -H "Content-Type: application/json" \
    -d '{"about": "Дереу — доставка мгновенно", "vertical": "RETAIL", "websites": ["https://kasip.chat"]}'
  ```

  Лимиты (валидируются, `422` при превышении): `about` ≤ 139 символов, `description` ≤ 512,
  `websites` — не более 2 URL, `vertical` — enum Meta (`RETAIL`, `FINANCE`, `HEALTH`, ... `NOT_A_BIZ`).
  Компания без подключённого WABA или чужая — `409`.
- **Фото Business Profile** — `POST /platform/companies/{external_id}/profile/photo` (platform key,
  `multipart/form-data`) — грузит фото профиля через Meta Resumable Upload API (upload-сессия →
  байты → `profile_picture_handle`). Допустимые типы: `image/jpeg`, `image/png`; лимит — 5 МБ (`422`
  при нарушении). Компания без подключённого WABA или чужая — `409`.

  ```bash
  curl -X POST "https://api.dereu.noderail.io/api/v1/platform/companies/org_123/profile/photo" \
    -H "Authorization: Bearer plat_xxxx.yyyy" -F "file=@avatar.jpg;type=image/jpeg"
  ```

## 8. Депровижининг

`DELETE /platform/companies/{external_id}` — симметрично провижинингу.

```bash
curl -X DELETE "https://api.dereu.noderail.io/api/v1/platform/companies/org_123?purge=true" \
  -H "Authorization: Bearer plat_xxxx.yyyy"
```

- Идемпотентно: несуществующий/чужой `external_id` → `404`; уже деактивированная → `410`.
- Компания **не удаляется физически** (аудит), но маршруты в `wabas` удаляются — роутинг и форвард
  входящих прекращаются немедленно.
- Входящие сообщения хранятся ещё 30 дней (retention-буфер, cron `dereu:purge-deactivated`) —
  `?purge=true` удаляет их немедленно.

**Ответ 200:** `{ "dereu_company_id": "co_abc123", "deactivated": true, "purged": false }`.

## 9. Безопасность

- Все вызовы — только по HTTPS/TLS.
- Platform key и API-ключи компаний — не коммитить в git, хранить в секрет-хранилище на вашей стороне.
- Входящий форвард от Dereu подписывается `X-Dereu-Signature` — обязательно проверяйте перед обработкой.
- System User Token и `phone_number_id` компаний хранятся зашифрованными **у нас**; партнёр их не получает
  и не хранит — весь доступ к Meta Cloud API идёт через Dereu.

## 10. Чеклист подключения

1. Запросить у оператора Dereu platform key + `app_id`/`config_id` для Embedded Signup + настроить общий
   форвард (`--webhook-url`).
2. Реализовать приём вебхука: проверка `X-Dereu-Signature`, обработка по `type`/`payload` (pass-through),
   дедуп по `wamid` (входящие) / `event_id` (доставки статусов).
3. Провижинить компанию (`POST /platform/companies`) при создании организации в вашем продукте — сохранить
   `dereu_company_id` и `api_key`.
4. Реализовать фронтенд Embedded Signup с нашим `app_id`/`config_id`, по завершении передать `code`/`waba_id`/
   `phone_number_id` в `POST /platform/companies/{external_id}/waba`.
5. Переключить отправку сообщений на `POST /messages/send` (используя `api_key` компании).
6. При удалении организации — вызвать `DELETE /platform/companies/{external_id}`.
