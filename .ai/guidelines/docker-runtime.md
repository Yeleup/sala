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

## Tests: only `make test`

Run tests ONLY via `make test` (or `make test-worktree` in a git worktree). Pass arguments through `test_args`:

```bash
make test test_args="--compact --filter=SomeTest"
```

Never run `php artisan test` directly — not on the host and not via `docker exec`. The `make test` target overrides the environment (`APP_ENV=testing`, `DB_DATABASE=$DB_TEST_DATABASE`) so tests hit the dedicated test database. A bare `php artisan test` inside the container uses the dev `.env`, and `RefreshDatabase` wipes the development database.

For anything not covered by the Makefile, use `docker exec` with the app container:

```bash
docker exec sala-app-1 php artisan tinker --execute='...'
```

## MCP servers

MCP servers such as Laravel Boost are launched through `docker exec -i` in `.mcp.json`. The stack must be running (`make up`) before an MCP client connects.

## Never do

- `php artisan ...`, `composer ...`, `vendor/bin/pint`, `vendor/bin/pest` directly on the host.
- `php artisan test` or `vendor/bin/pest` via `docker exec` — it runs against the dev database and destroys its data; use `make test`.
- Starting the app with `php artisan serve` or `composer run dev` — use `make up` / `make build`.
