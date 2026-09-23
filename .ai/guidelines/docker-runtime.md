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
