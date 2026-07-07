<laravel-boost-guidelines>
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

make shell                               # shell in the app container

```

For anything not covered by the Makefile, use `docker exec` with the app container:

```bash
docker exec sala-app-1 php artisan tinker --execute='...'
```

## MCP servers

MCP servers such as Laravel Boost are launched through `docker exec -i` in `.mcp.json`. The stack must be running (`make up`) before an MCP client connects.

## Never do

- `php artisan ...`, `composer ...`, `vendor/bin/pint`, `vendor/bin/pest` directly on the host.
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

=== .ai/storefront-design-preview rules ===

# Storefront Design Preview

Any change to storefront UI or storefront-facing visual design MUST be reflected in:

resources/views/storefront-design-preview.blade.php

Do this even when the actual implementation also changes another Blade view, Livewire component, Vue/React component, or CSS/Tailwind classes.

This applies to product pages, category pages, product cards, storefront homepage sections, mobile layouts, responsive states, and visual mockups.

The preview update must be a close visual match to the production UI, not just a short mention or rough approximation of the new feature. Match the relevant Blade/component structure, layout, controls, labels, spacing, and states closely enough that the preview can be used for design review.

When a storefront page has both mobile and desktop preview states, update every relevant viewport/state shown in `storefront-design-preview.blade.php`. Do not update only mobile or only desktop unless the changed screen exists in the preview for only that viewport.

=== foundation rules ===

# Laravel Boost Guidelines

The Laravel Boost guidelines are specifically curated by Laravel maintainers for this application. These guidelines should be followed closely to ensure the best experience when building Laravel applications.

## Foundational Context

This application is a Laravel application and its main Laravel ecosystems package & versions are below. You are an expert with them all. Ensure you abide by these specific packages & versions.

- php - 8.4
- laravel/framework (LARAVEL) - v13
- laravel/octane (OCTANE) - v2
- laravel/prompts (PROMPTS) - v0
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
