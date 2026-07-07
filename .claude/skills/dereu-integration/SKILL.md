---
name: dereu-integration
description: "Use this skill whenever working with the Dereu WhatsApp hub integration: sending WhatsApp messages, OTP, or templates through the Dereu API; handling inbound webhook forwards from Dereu (message_received, delivery statuses, signature verification, deduplication); provisioning or deprovisioning companies; WABA onboarding via Meta Embedded Signup or system_user_token migration; coexistence/SMB numbers; media upload/download; product catalogs and feeds; Business Profile updates. Trigger on any mention of Dereu, platform key, dereu_ API keys, phone_number_id routing, X-Dereu-Signature, or WhatsApp Cloud API access in this project — all Meta/WhatsApp traffic goes through Dereu, never directly to Meta Graph API."
---

# Dereu Integration

This project connects to **Dereu** as its WhatsApp hub. Dereu is a Meta Tech Provider: it stores clients' WABA/System User Tokens, receives the single Meta webhook, and routes events by `phone_number_id` to the partner. **Never call Meta Graph API / Cloud API directly** — all WhatsApp traffic goes through Dereu.

Full contract with request/response examples: [reference.md](reference.md). Machine-readable spec: `services/api/public/openapi.yaml` on the Dereu API service (also served at `/docs`).

## Model

```
Partner (this project) → platform key (plat_...)
  └─ Company (Dereu tenant, external_id = our internal org id, 1:1 with a WhatsApp number)
       └─ phone_number_id
```

## Two auth mechanisms — never mix them

| Key | Header | Scope |
|---|---|---|
| Platform key `plat_<prefix>.<secret>` | `Authorization: Bearer plat_...` | M2M: provisioning/deprovisioning, WABA onboarding, catalogs, business profile. Not tied to a company. |
| Company API key `dereu_...` | `Authorization: Bearer dereu_...` | Messaging: `/messages/send`, `/otp/*`, `/optin`, `/media`. Tied to one company. |

Both keys are shown **only once** (platform key at issuance, company key in the `POST /platform/companies` 201 response) — persist them to the secret store immediately. Never commit to git.

## Core flows

- **Provision company:** `POST /platform/companies {external_id, name}` — idempotent by `external_id`; 201 returns `api_key` once, repeat call returns 200 `already_provisioned` without the key.
- **Onboard number:** run Meta Embedded Signup in our frontend with Dereu's `app_id`/`config_id`, then send `code` + `waba_id` + `phone_number_id` to `POST /platform/companies/{external_id}/waba`. Exactly one of `code`/`system_user_token` is required.
- **Send message:** `POST /messages/send` with the **company** key: `{phone_number_id, to, type, payload}`. `payload` is pass-through to Meta Cloud API. Response is `202 {id, status: queued}` — actual delivery outcome arrives as `message_sent/delivered/read/failed` webhook events.
- **Inbound webhooks:** one shared URL + secret for the whole project (configured by the Dereu operator with the platform key); tenants are distinguished by `company_id` in the event body. Verify `X-Dereu-Signature: sha256=HMAC-SHA256(secret, raw_body)` **before** parsing JSON. Respond 2xx fast; delivery retries with backoff, then dead-letters on the Dereu side.
- **Deprovision:** `DELETE /platform/companies/{external_id}` (add `?purge=true` to drop the 30-day inbound retention buffer immediately).

## Gotchas (cause real bugs)

- **Deduplication keys differ:** for inbound messages dedupe by `wamid` (one message can be delivered more than once with different `event_id`s); for delivery-status events dedupe by `event_id` (one `wamid` legitimately produces sent+delivered+read).
- **Embedded Signup `code` is single-use:** any retry with the same `code` fails (`422` or after a `502`) — reopen the popup for a fresh code, never retry the same one.
- **`system_user_token` migration path skips `/register` and `/subscribed_apps`** — the token belongs to the partner's Meta app; subscribing with it would break webhook signatures. The Dereu-app subscription must already exist.
- **Coexistence/SMB numbers** (`account_mode: "coexistence"`): registration step is skipped (no PIN), and `GET .../catalogs` always fails with Meta error `#10` — pick catalogs only from `owned` in the `POST .../waba` response.
- **`type`/`payload` in events are raw Meta pass-through** (`messages[].type` / `messages[].<type>`) — the type list is open, handle unknown types gracefully.
- **Media:** `payload.id` and `payload.link` are mutually exclusive (exactly one). If the file has a public HTTPS URL, pass `payload.link` — `POST /media` is only for local files without a URL. Download inbound media via `GET /media/{media_id}` (company key) — Dereu proxies bytes; we never see the System User Token.
- **`marketing: true` requires prior opt-in** (`POST /optin`), otherwise `403 opt_in_required`.
- **Throttle:** 60/min per company on `/messages/send`; Meta's own per-number limits surface later as `message_failed` events, not as send-time errors.
- **`business_app_message_echo` / `business_app_contact_sync`** (coexistence events) are currently **not forwarded** by Dereu — don't build logic expecting them without confirming with the Dereu operator.
- **Templates have no M2M endpoint** — template management is only available through the company owner's web session on the Dereu side.

## Project rules

- Follow `.ai/guidelines/project-documentation.md`: Dereu-related business behavior (session window handling, template usage, opt-in policy) belongs in `docs/`; endpoint mechanics stay here.
- WhatsApp business rules of this product (24h window, Template Message triggers, 30-day listing revalidation) are defined in `docs/business-rules.md` — this skill covers only the transport layer through Dereu.
