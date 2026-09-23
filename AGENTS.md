<laravel-boost-guidelines>
=== .ai/agent-queue rules ===

# Agent Task Queue

Code changes are done through a queue of GitHub issues (label `agent`) worked by coordinators that follow the `implement-review` skill: one agent implements, another reviews, the PR is merged after a clean review.

The queue rules below apply only to **entry sessions**: a session in the main checkout. A worker in a task worktree or an Orca coordinator ignores them and does the task it was given; a reviewer follows «Reviewing a queue task».

When the user asks an entry session for a code change (feature, bug fix, refactoring, migration, UI change):

- Put it in the queue: write the user's request, with the context from the conversation, to a file and run `bash "$(dirname "$(git rev-parse --path-format=absolute --git-common-dir)")/.ai/skills/implement-review/queue.sh" enqueue "<short Russian title>" <file>` (the main checkout's copy of the script). Reply with the issue link; a coordinator picks it up on its own.
- Settle every open interpretation with the user (AskUserQuestion) before enqueueing: a coordinator claims a new issue within minutes and reads the thread once, so «напишите — поправлю задачу» comes too late.
- In the issue body keep «Запрос пользователя» (quoted), «Решения пользователя» and your own proposals apart; ask about any proposal that changes existing behaviour, or leave it out.
- The repository is public: the issue never contains data from the local database or real conversations (phone numbers, names, WhatsApp message texts, ids of real contacts or listings, tokens or keys). Describe the case in general terms.
- Run `queue.sh list` first. If the change needs code from an open queue issue, add a line `Зависит от #N` (the queue holds the issue until #N is closed); if it only touches the same module, add «Связано с #N». After enqueueing, `queue.sh list` shows `waits:#N` for a registered dependency.
- Enqueueing replaces implementation in any effort or workflow mode.
- If the user says «сразу» (now): enqueue it. Only in an Orca terminal (`ORCA_TERMINAL_HANDLE` is set), claim that issue yourself (`queue.sh claim "orca:$ORCA_TERMINAL_HANDLE" <number>`) and coordinate it per the `implement-review` skill; anywhere else say that the Orca automation picks it up within about 5 minutes.
- If the user says «сделай сам» or «без очереди»: do the change yourself in this session, without the queue.

Questions, analysis, code review, SQL for production data fixes and other work that does not change repository files are answered directly, without the queue.

## Reviewing a queue task

If you review a pull request or branch named `agent/issue-<N>`, whatever tool started you:

- do not modify, commit or push anything, and do not spawn sub-agents;
- in the task worktree (`HEAD` is that branch): review `git diff origin/master...HEAD` against issue #<N> (`gh issue view <N>`; only the issue author's comments count), the relevant `docs/` and these project rules, and run the full `make test`;
- anywhere else (for example the main checkout): review `gh pr diff <pr>` the same way; `make test` here would test other code, so report «make test не запускался» unless you ran it in a worktree checked out at that branch;
- report every finding with severity, `file:line`, the failure scenario and a suggested fix, then the reviewed SHA and the `make test` summary, and end with the line `VERDICT: approve` or `VERDICT: changes`.

=== .ai/docker-runtime rules ===

# Docker Runtime

This project runs entirely inside Docker. Host PHP must never be used: its version and extensions differ from the container runtime, and service hostnames (`db`, `redis`) only resolve inside the compose network.

## Required commands

Run every PHP, Composer, and Node command inside the containers:

```bash
make artisan artisan_args="route:list"   # php artisan ...

make composer composer_args="install"    # composer ...

make npm npm_args="run build"            # npm ...

make test                                # php artisan test

make pint                                # vendor/bin/pint --format agent on uncommitted PHP files

make shell                               # shell in the app container

```

`make artisan`, `make composer`, `make test`, `make pint` and `make shell` start one-off containers that mount the current checkout (the main checkout or a git worktree), so only the database has to be running.

## Tests: only `make test`

Run tests ONLY via `make test`, in the main checkout and in git worktrees alike. Pass arguments through `test_args`:

```bash
make test test_args="--compact --filter=SomeTest"
```

Never run `php artisan test` directly — not on the host and not via `docker exec`. The `make test` target overrides the environment (`APP_ENV=testing`, `DB_DATABASE=$DB_TEST_DATABASE`) so tests hit the dedicated test database. A bare `php artisan test` inside the container uses the dev `.env`, and `RefreshDatabase` wipes the development database.

For anything not covered by the Makefile, use `make artisan` (keep `$` out of the PHP code: make would expand it):

```bash
make artisan artisan_args="tinker --execute='echo App\Models\User::count();'"
```

In the main checkout `docker exec` into the running app container works as well (`XDG_CONFIG_HOME=/tmp` lets tinker write its config):

```bash
docker exec -e XDG_CONFIG_HOME=/tmp sala-app-1 php artisan tinker --execute='...'
```

`sala-app-1` runs the main checkout's code and database: in a git worktree use `make artisan` or `make shell` instead.

## MCP servers

Laravel Boost runs through `make boost-mcp` (see `.mcp.json`) in a one-off container for the checkout the MCP client was started in. That checkout's database must be running (`make up` or `make db-up`; a git worktree also uses the main checkout's).

## Never do

- `php artisan ...`, `composer ...`, `vendor/bin/pint`, `vendor/bin/pest` directly on the host.
- `php artisan test` or `vendor/bin/pest` via `docker exec` — it runs against the dev database and destroys its data; use `make test`.
- `docker exec sala-app-1 ...` from a git worktree — it runs the main checkout's code (Pint formats the main checkout's files); use `make pint`, `make artisan`, `make shell`.
- Starting the app with `php artisan serve` or `composer run dev` — use `make up` / `make build`.

=== .ai/laravel-docker-template rules ===

# Laravel Docker Template

The `laravel-docker-template` directory is the reusable source template for this project's Docker setup.

When changing Docker, runtime, or local development configuration in the project root, make the equivalent generic change in `laravel-docker-template` during the same task.

Application containers load the project's `.env` through `env_file`, so new environment variables reach `app`, queue, and scheduler containers after a recreate/redeploy without compose changes. Only add a variable to the `x-app-environment` block when its value must be overridden inside Docker (for example `DB_HOST`, `REDIS_HOST`). Never copy project-specific variables or secrets into `laravel-docker-template`.

This applies to:

- `.dockerignore`
- `.env.docker.example`
- `Dockerfile`
- `Makefile`
- `docker-compose.yml`
- `docker-compose.override.yml`
- `docker/app/*`
- `docker/worktree/*`
- `orca.yaml`
- Docker-related agent guidelines (`.ai/guidelines/docker-runtime.md`, `.ai/guidelines/worktrees.md`)
- Docker-related README instructions

Keep the template reusable. Do not copy project-specific secrets, local machine paths, app names, generated files, or one-off values into `laravel-docker-template` unless the change is intentionally part of the reusable template.

=== .ai/project-ai-design rules ===

## Project AI Design Rules

- Never propose or implement phrase-level hardcoding as the primary solution for AI intent detection, conversation follow-ups, language handling, or catalog/search behavior. Do not solve ambiguous context by checking for specific customer phrases or wording variants. Prefer explicit conversation state, structured intent classification, typed tool parameters, semantic constraints, or changes to the tool contract. If a keyword guard is truly unavoidable as a temporary production safety patch, state that it is a stopgap and ask for approval before implementing it.

=== .ai/project-documentation rules ===

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
- AI assistant behavior: clarification question limits (per kind: 3 / 4 / 6 attempts, plus a no-progress cutoff) and forced handoff to the web interface.
- WhatsApp messaging rules: 24-hour window handling, template message usage, CTA redirects.
- Handling of concurrent requests for the same equipment (no locking; resolved via communication).

Update `docs/changelog.md` when business rules or module behavior change.

A task is not complete until related documentation is updated.

## Documentation scope: behavior, not implementation

To prevent documentation drift, `docs/` describes **what the system does and why** — never **how the code implements it**.

Do document: business rules, statuses and transitions, limits and thresholds, user-visible flows, external API constraints, edge-case decisions and their rationale.

Do not document: class/method/table/column names, directory structure, framework mechanics (jobs, events, middleware), or anything derivable from the code itself. If a refactoring changes no user-visible behavior and no business rule, no documentation update is required — and none should be made.

`docs/changelog.md` records business-rule and behavior changes only; technical changes belong to git history.

=== .ai/storefront-design-preview rules ===

# Storefront Design Preview

Any change to storefront UI or storefront-facing visual design MUST be reflected in:

resources/views/storefront-design-preview.blade.php

Do this even when the actual implementation also changes another Blade view, Livewire component, Vue/React component, or CSS/Tailwind classes.

This applies to product pages, category pages, product cards, storefront homepage sections, mobile layouts, responsive states, and visual mockups.

The preview update must be a close visual match to the production UI, not just a short mention or rough approximation of the new feature. Match the relevant Blade/component structure, layout, controls, labels, spacing, and states closely enough that the preview can be used for design review.

When a storefront page has both mobile and desktop preview states, update every relevant viewport/state shown in `storefront-design-preview.blade.php`. Do not update only mobile or only desktop unless the changed screen exists in the preview for only that viewport.

=== .ai/worktrees rules ===

# Git Worktrees (Orca, Claude Code, Codex)

Parallel agents work in git worktrees: Orca creates them in `~/orca/workspaces/<repo>/<name>`, Claude Code in `.claude/worktrees/<name>`. You are in a worktree when `git rev-parse --path-format=absolute --git-dir` differs from `git rev-parse --path-format=absolute --git-common-dir` (without `--path-format=absolute` they also differ in subdirectories of the main checkout).

- The main checkout's running stack (its `APP_URL`, Vite port, `sala-*` containers and `docker exec sala-app-1`) serves the MAIN checkout's code and database. Never use it to verify or change a worktree.
- A worktree gets its own `.env` from `make worktree-setup` (Orca runs it through `orca.yaml` before the agent starts): `WORKTREE_MANAGED=1`, its own `DOCKER_PROJECT_NAME`, ports, databases and database role. Never copy or symlink the main `.env`, never run `make init` in a worktree, never point `DOCKER_PROJECT_NAME` or `docker compose -p` at the main project.
- If `.env` or `vendor/` is missing, or make says the `.env` "was not generated for this git worktree" or that `vendor/` is shared with the main checkout, run `make worktree-setup` and nothing else: it moves a copied `.env` aside to `.env.pre-worktree`, writes this worktree's own, and replaces a `vendor/` that shares files with the main checkout.
- `make test`, `make artisan`, `make composer`, `make pint` and `make shell` run in one-off containers that mount the current checkout, so they test and change THIS worktree.
- `make test` uses the worktree's own test database on the main checkout's PostgreSQL, so parallel agents do not collide. "No running database": ask the user to start the main stack, or run `make db-up` (only this worktree's db and redis).
- After adding migrations: `make artisan artisan_args="migrate"` (the worktree's own database). Boost MCP answers for this worktree's code and database.
- On the main checkout's PostgreSQL the worktree role is not a superuser and gets only the extensions the main database already has. A migration that adds another untrusted extension fails there with "permission denied to create extension": run `make db-up` (this worktree's own PostgreSQL, where its role is the superuser) and test against it.
- UI checks: `make up` starts an isolated stack at `APP_URL` from the worktree's `.env` with a freshly migrated, empty database (`make artisan artisan_args="db:seed"` to fill it); `make down` when finished.
- After changing `Dockerfile` or `docker/app/*`: `make build` in the worktree (one-off containers otherwise use the main checkout's image).
- `docs/changelog.md`: add your entry without reordering existing ones; expect merge conflicts there.
- Deleting a worktree: `make worktree-archive` in it, or `orca worktree rm --run-hooks ...` (without `--run-hooks` Orca skips the cleanup and leaves the databases, role and containers behind; `make worktree-prune CONFIRM=yes` in the main checkout removes them later).
- After a branch is merged, migrations do not run by themselves in the main checkout: `make artisan artisan_args="migrate"` there.

=== foundation rules ===

# Laravel Boost Guidelines

The Laravel Boost guidelines are specifically curated by Laravel maintainers for this application. These guidelines should be followed closely to ensure the best experience when building Laravel applications.

## Foundational Context

This application is a Laravel application and its main Laravel ecosystems package & versions are below. You are an expert with them all. Ensure you abide by these specific packages & versions.

- php - 8.4
- filament/filament (FILAMENT) - v5
- laravel/ai (AI) - v0
- laravel/framework (LARAVEL) - v13
- laravel/octane (OCTANE) - v2
- laravel/prompts (PROMPTS) - v0
- livewire/livewire (LIVEWIRE) - v4
- laravel/boost (BOOST) - v2
- laravel/mcp (MCP) - v0
- laravel/pail (PAIL) - v1
- laravel/pint (PINT) - v1
- pestphp/pest (PEST) - v4
- phpunit/phpunit (PHPUNIT) - v12
- tailwindcss (TAILWINDCSS) - v4

## Skills Activation

This project has domain-specific skills available in `**/skills/**`. You MUST activate the relevant skill whenever you work in that domain—don't wait until you're stuck.

## Conventions

- You must follow all existing code conventions used in this application. When creating or editing a file, check sibling files for the correct structure, approach, and naming.
- Use descriptive names for variables and methods. For example, `isRegisteredForDiscounts`, not `discount()`.
- Check for existing components to reuse before writing a new one.

## Verification Scripts

- Do not create verification scripts or tinker when tests cover that functionality and prove they work. Unit and feature tests are more important.

## Application Structure & Architecture

- Stick to existing directory structure; don't create new base folders without approval.
- Do not change the application's dependencies without approval.

## Frontend Bundling

- If the user doesn't see a frontend change reflected in the UI, it could mean they need to run `npm run build`, `npm run dev`, or `composer run dev`. Ask them.

## Documentation Files

- You must only create documentation files if explicitly requested by the user.

## Replies

- Be concise in your explanations - focus on what's important rather than explaining obvious details.

=== boost rules ===

# Laravel Boost

## Tools

- Laravel Boost is an MCP server with tools designed specifically for this application. Prefer Boost tools over manual alternatives like shell commands or file reads.
- Use `database-query` to run read-only queries against the database instead of writing raw SQL in tinker.
- Use `database-schema` to inspect table structure before writing migrations or models.
- Use `get-absolute-url` to resolve the correct scheme, domain, and port for project URLs. Always use this before sharing a URL with the user.
- Use `browser-logs` to read browser logs, errors, and exceptions. Only recent logs are useful, ignore old entries.

## Searching Documentation (IMPORTANT)

- Always use `search-docs` before making code changes. Do not skip this step. It returns version-specific docs based on installed packages automatically.
- Pass a `packages` array to scope results when you know which packages are relevant.
- Use multiple broad, topic-based queries: `['rate limiting', 'routing rate limiting', 'routing']`. Expect the most relevant results first.
- Do not add package names to queries because package info is already shared. Use `test resource table`, not `filament 4 test resource table`.

### Search Syntax

1. Use words for auto-stemmed AND logic: `rate limit` matches both "rate" AND "limit".
2. Use `"quoted phrases"` for exact position matching: `"infinite scroll"` requires adjacent words in order.
3. Combine words and phrases for mixed queries: `middleware "rate limit"`.
4. Use multiple queries for OR logic: `queries=["authentication", "middleware"]`.

## Artisan

- Run Artisan commands directly via the command line (e.g., `php artisan route:list`). Use `php artisan list` to discover available commands and `php artisan [command] --help` to check parameters.
- Inspect routes with `php artisan route:list`. Filter with: `--method=GET`, `--name=users`, `--path=api`, `--except-vendor`, `--only-vendor`.
- Read configuration values using dot notation: `php artisan config:show app.name`, `php artisan config:show database.default`. Or read config files directly from the `config/` directory.

## Tinker

- Execute PHP in app context for debugging and testing code. Do not create models without user approval, prefer tests with factories instead. Prefer existing Artisan commands over custom tinker code.
- Always use single quotes to prevent shell expansion: `php artisan tinker --execute 'Your::code();'`
  - Double quotes for PHP strings inside: `php artisan tinker --execute 'User::where("active", true)->count();'`

=== php rules ===

# PHP

- Always use curly braces for control structures, even for single-line bodies.
- Use PHP 8 constructor property promotion: `public function __construct(public GitHub $github) { }`. Do not leave empty zero-parameter `__construct()` methods unless the constructor is private.
- Use explicit return type declarations and type hints for all method parameters: `function isAccessible(User $user, ?string $path = null): bool`
- Use TitleCase for Enum keys: `FavoritePerson`, `BestLake`, `Monthly`.
- Prefer PHPDoc blocks over inline comments. Only add inline comments for exceptionally complex logic.
- Use array shape type definitions in PHPDoc blocks.

=== deployments rules ===

# Deployment

- Laravel can be deployed using [Laravel Cloud](https://cloud.laravel.com/), which is the fastest way to deploy and scale production Laravel applications.

=== tests rules ===

# Test Enforcement

- Every change must be programmatically tested. Write a new test or update an existing test, then run the affected tests to make sure they pass.
- Run the minimum number of tests needed to ensure code quality and speed. Use `php artisan test --compact` with a specific filename or filter.

=== laravel/core rules ===

# Do Things the Laravel Way

- Use `php artisan make:` commands to create new files (i.e. migrations, controllers, models, etc.). You can list available Artisan commands using `php artisan list` and check their parameters with `php artisan [command] --help`.
- If you're creating a generic PHP class, use `php artisan make:class`.
- Pass `--no-interaction` to all Artisan commands to ensure they work without user input. You should also pass the correct `--options` to ensure correct behavior.

### Model Creation

- When creating new models, create useful factories and seeders for them too. Ask the user if they need any other things, using `php artisan make:model --help` to check the available options.

## APIs & Eloquent Resources

- For APIs, default to using Eloquent API Resources and API versioning unless existing API routes do not, then you should follow existing application convention.

## URL Generation

- When generating links to other pages, prefer named routes and the `route()` function.

## Testing

- When creating models for tests, use the factories for the models. Check if the factory has custom states that can be used before manually setting up the model.
- Faker: Use methods such as `$this->faker->word()` or `fake()->randomDigit()`. Follow existing conventions whether to use `$this->faker` or `fake()`.
- When creating tests, make use of `php artisan make:test [options] {name}` to create a feature test, and pass `--unit` to create a unit test. Most tests should be feature tests.

## Vite Error

- If you receive an "Illuminate\Foundation\ViteException: Unable to locate file in Vite manifest" error, you can run `npm run build` or ask the user to run `npm run dev` or `composer run dev`.

=== octane/core rules ===

# Laravel Octane

This application uses Laravel Octane, a long-running PHP server. The application bootstraps once and handles many requests within the same process.

- Never store request-specific state in singletons or static properties, because it can leak across requests.
- Use `config('octane.server')` to detect the active driver (`swoole`, `roadrunner`, or `frankenphp`).
- Prefer scoped bindings (`$this->app->scoped()`) over singletons for per-request services.

When working on Octane-specific features (concurrency, shared tables, memory, driver configuration, testing), invoke `octane-development` for detailed rules.

=== pint/core rules ===

# Laravel Pint Code Formatter

- If you have modified any PHP files, you must run `vendor/bin/pint --dirty --format agent` before finalizing changes to ensure your code matches the project's expected style.
- Do not run `vendor/bin/pint --test --format agent`, simply run `vendor/bin/pint --format agent` to fix any formatting issues.

=== pest/core rules ===

## Pest

- This project uses Pest for testing. Create tests: `php artisan make:test --pest {name}`.
- The `{name}` argument should not include the test suite directory. Use `php artisan make:test --pest SomeFeatureTest` instead of `php artisan make:test --pest Feature/SomeFeatureTest`.
- Run tests: `php artisan test --compact` or filter: `php artisan test --compact --filter=testName`.
- Do NOT delete tests without approval.

</laravel-boost-guidelines>
