# Project Documentation Guideline

This project uses documentation as the source of truth for business logic and module behavior. The original technical specification lives in Google Docs (see `docs/technical-specification.md` for the maintained copy).

Before implementing or changing business logic, check:

- `docs/technical-specification.md`
- `docs/business-rules.md`
- relevant files in `docs/modules/*.md`

When changing business logic or user-visible module behavior, update the relevant documentation in the same task.

Documentation files required by this guideline are considered explicitly requested by the project. This is an exception to the general rule that documentation files should not be created unless explicitly requested.

If a relevant module documentation file does not exist yet, create it before implementing the feature.

Core project modules include:

- **Bot & scenario constructor** (`docs/modules/bot-constructor.md`) — no-code branching dialog scenarios, soft updates of active sessions.
- **AI assistant & data processing** (`docs/modules/ai-assistant.md`) — collecting supplier data with clarifying questions, text matching of listings to customer requests, clarification attempt limits.
- **WhatsApp integration & web interface** (`docs/modules/whatsapp-integration.md`) — WhatsApp Cloud API constraints, 24-hour session window, paid template messages, CTA URL handoff to the web app.
- **Entities, fields & statuses** (`docs/modules/listings-lifecycle.md`) — listing lifecycle (draft → moderation → published → archive), 30-day expiry field.
- **User scenarios** (`docs/modules/user-flows.md`) — supplier flow (adding listings) and customer flow (search and service request).

Business logic changes include (non-exhaustive):

- Listing lifecycle: statuses, transitions, moderation rules, the 30-day expiry and renewal cycle.
- Matching rules between customer requests and supplier listings, including text-based geolocation handling.
- Dialog scenario structure, branching conditions, and how active sessions are updated.
- AI assistant behavior: clarification question limits (2–3 attempts) and forced handoff to the web interface.
- WhatsApp messaging rules: 24-hour window handling, template message usage, CTA redirects.
- Handling of concurrent requests for the same equipment (no locking; resolved via communication).

Update `docs/changelog.md` when business rules or module behavior change.

A task is not complete until related documentation is updated.

## Documentation scope: behavior, not implementation

To prevent documentation drift, `docs/` describes **what the system does and why** — never **how the code implements it**.

Do document: business rules, statuses and transitions, limits and thresholds, user-visible flows, external API constraints, edge-case decisions and their rationale.

Do not document: class/method/table/column names, directory structure, framework mechanics (jobs, events, middleware), or anything derivable from the code itself. If a refactoring changes no user-visible behavior and no business rule, no documentation update is required — and none should be made.

`docs/changelog.md` records business-rule and behavior changes only; technical changes belong to git history.
