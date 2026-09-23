# Laravel Docker Template

Reusable Docker setup for new Laravel projects that run with Laravel Octane and FrankenPHP.

## What is included

- `Dockerfile` for PHP (FrankenPHP), Composer, and Node build assets. PHP and Node versions are configurable through `PHP_VERSION` and `NODE_VERSION` in `.env`.
- `docker-compose.yml` for app, queue, scheduler, PostgreSQL (pgvector image, so the `vector` extension is available when needed), and Redis.
- `docker-compose.override.yml` for local development with bind mounts and Vite.
- `Makefile` with common commands for build, start, logs, tests, dumps, and deploy.
- `.env.docker.example` with Docker-specific variables that `make init` adds to the target project's `.env`.
- `docker/worktree/worktree.sh` and `orca.yaml` for running several git worktrees (parallel coding agents) side by side.

## Copy into a project

Copy the contents of this folder into the root of a Laravel project:

```bash
cp -a laravel-docker-template/. /path/to/your-laravel-project/
```

Do not copy the folder itself if you want the Docker files to work from the project root.

## Required project dependency

The target Laravel project must have Octane installed with FrankenPHP:

```bash
composer require laravel/octane
php artisan octane:install --server=frankenphp
```

If you want to install it through Docker after copying the template:

```bash
export DOCKER_PROJECT_NAME=your-project
docker compose -f docker-compose.yml -f docker-compose.override.yml run --build --rm --no-deps --entrypoint composer app require laravel/octane --no-scripts
docker compose -f docker-compose.yml -f docker-compose.override.yml run --rm --no-deps --entrypoint php app artisan package:discover --ansi
docker compose -f docker-compose.yml -f docker-compose.override.yml run --rm --no-deps --entrypoint php app artisan octane:install --server=frankenphp
```

`docker-compose.yml` requires `DOCKER_PROJECT_NAME`: there is no shared default, so two projects (or two checkouts of one project) never end up in the same compose project by accident.

Using `--no-scripts` avoids project-specific Composer hooks blocking Octane installation. For example, a project may have `@php artisan boost:update --ansi` in `post-update-cmd`; that command requires Boost to be installed first with `php artisan boost:install`.

## Environment variables

`make init` adds missing variables from `.env.docker.example` to the target project's `.env`. Existing `.env` values are not overwritten. An empty `DOCKER_PROJECT_NAME` is set to the project directory name.

After that, change the project-specific values:

```env
DOCKER_PROJECT_NAME=your-project
APP_NAME="Your Project"
APP_PORT=8500
APP_URL=http://localhost:8500

DB_CONNECTION=pgsql
DB_DATABASE=your_project
DB_TEST_DATABASE=your_project_testing
FORWARD_DB_PORT=5450
VITE_PORT=5174
```

Use different ports when running multiple projects at the same time.

## Port allocation for multiple projects

Give every project a number `N` and a port block `8000 + N * 10`. The app listens on the block base, the database forward on base + 1, and Vite on base + 2, so project 3 gets 8030/8031/8032. One command writes the whole block into `.env` (it also updates a localhost `APP_URL`):

```bash
make ports PORT_BASE=8030
```

To see which ports are already taken by containers on the machine:

```bash
docker ps --format '{{.Names}}\t{{.Ports}}'
```

Project-specific environment variables do not need to be added to `docker-compose.yml`: every service loads the project's `.env` through `env_file`, so new variables reach the containers after a recreate. The `x-app-environment` block only overrides values that must differ inside Docker (`DB_HOST`, `REDIS_HOST`, and similar).

## Start locally

```bash
make init
make build
```

Open the project at the `APP_URL` value from `.env`.

Useful commands:

```bash
make up
make down
make logs
make ps
make shell
make test
make pint
make dump
make import
```

`make dump` writes the app database to `docker/db/dump.sql.gz`. `make import` recreates the database from that file.

`make test`, `make artisan`, `make composer`, `make pint` and `make shell` run in one-off containers (`docker compose run --rm`) that mount the current checkout, so only the database has to be running (`make db-up` starts just `db` and `redis`). `make up`, `make build`, `make down` and the other stack commands refuse to touch a compose project whose containers were created from another directory. Volumes carry no such owner, so keep `DOCKER_PROJECT_NAME` unique per checkout.

`make pint` formats the checkout's uncommitted PHP files: git lists them on the host, because `pint --dirty` needs git and the app image has none.

## MCP servers (Laravel Boost)

Run MCP servers in Docker so PHP, extensions, and service hostnames match the runtime. `make boost-mcp` starts the Boost stdio server in a one-off container for the checkout it is run from, so the same `.mcp.json` serves the main checkout and every git worktree:

```json
{
    "mcpServers": {
        "laravel-boost": {
            "command": "sh",
            "args": ["-c", "cd \"$(git rev-parse --show-toplevel)\" && exec make --no-print-directory -s boost-mcp"]
        }
    }
}
```

The checkout's database must be running (`make up` or `make db-up`) before the MCP client connects. The same applies to artisan and composer in general: run them through `make artisan` / `make composer`, not with host PHP.

## Git worktrees (Orca, Claude Code, `git worktree add`)

Every git worktree needs its own `.env`: a copy of the main `.env` would make the worktree's `make up` take over the main checkout's containers and `make test` test the main checkout's code. Run this in a new worktree:

```bash
make worktree-setup
```

It (see `docker/worktree/worktree.sh`):

- writes the worktree `.env` from the main checkout's `.env` with its own `DOCKER_PROJECT_NAME` (`<main>-wt-<name>-<id>`), `APP_PORT`/`APP_URL`, `FORWARD_DB_PORT`, `VITE_PORT` (a free slot in a per-project block of 20000-29900, or `WORKTREE_PORT_BASE`), databases (`<db>_wt_<name>_<id>` and `DB_TEST_DATABASE` `..._test`), a database role `wt_<id>` with a random password, and `SESSION_COOKIE`;
- copies `vendor/` from the main checkout (a real copy, never a symlink or hardlinks) and runs `composer install`;
- creates the role and the databases on the main checkout's PostgreSQL and runs the migrations.

The worktree role is not a superuser there. It owns its databases and may create more (Laravel parallel-test databases `..._test_<n>`), but cannot create untrusted extensions such as pgvector's `vector`: every `make test` installs the extensions of the main database into `template1` and into each database the role owns, so the databases the role creates itself get them too.

Two changes belong to the project, not to this template:

- add `/vendor.tmp.*` to `.gitignore` (the vendor copy is written there first);
- call `$this->withoutVite()` in the base `TestCase`, or build assets in every worktree: a fresh worktree has neither `public/hot` nor `public/build`, so tests that render a view with `@vite` fail.

After that, in the worktree:

- `make test`, `make artisan`, `make composer`, `make pint`, `make shell` and the Boost MCP server (`make boost-mcp`) use one-off containers on the main checkout's network with the worktree's own databases and role, and with file cache, file sessions and the sync queue instead of the shared Redis and queue worker;
- `make test` creates the worktree role and databases if needed (for example after `make down-volumes` in the main checkout), so parallel worktrees never share a test database;
- `make up` starts a fully isolated stack (own containers, ports, volumes and an empty database, where the worktree role is the superuser); `make db-up` starts only its own `db` and `redis`, for when the main stack is down or a migration needs an extension the main database does not have yet;
- `make worktree-archive` removes the worktree's containers, volumes, image tag, databases and database role; run it before deleting the worktree.

`make worktree-prune` (in any checkout) lists leftovers of deleted worktrees; `make worktree-prune CONFIRM=yes` removes them.

### Orca

`orca.yaml` runs `make worktree-setup` when Orca creates a workspace (the agent waits for it) and the cleanup when Orca deletes it. In Orca's repository settings:

- keep **Command source** on **orca.yaml only** (the default while the repository has no local scripts), or use **Run both** with a local setup script that fails for branches without this setup: `test -f docker/worktree/worktree.sh || { echo 'Branch has no worktree setup: merge the main branch, then run make worktree-setup.' >&2; exit 1; }`;
- leave **Worktree Shared Paths** empty (never share `.env`, `vendor` or `node_modules`);
- delete workspaces from the desktop app, or pass `--run-hooks` to `orca worktree rm`, otherwise the cleanup does not run.

Worktree support needs Linux tools: `flock`, `ss`, `realpath`, `cp --reflink`.

## Troubleshooting

If the Vite container restarts with `ENOSPC: System limit for number of file watchers reached`, exclude heavy directories from the Vite watcher in the project's `vite.config.js`:

```js
server: {
    watch: {
        ignored: ['**/vendor/**', '**/storage/**', '**/.git/**', '**/.claude/**'],
    },
},
```

`.claude/**` covers Claude Code worktrees, which live inside the main checkout. The host inotify watcher limit is shared by all containers, so this shows up when several projects run Vite at the same time. Alternatively raise `fs.inotify.max_user_watches` on the host or set `VITE_USE_POLLING=1` in `.env`.

## Production mode

Set `APP_ENV=production` in `.env` and run:

```bash
make build
```

For deploy on a server:

```bash
make deploy
```

Upgrading a server set up before `DOCKER_PROJECT_NAME` became required: if its `.env` has no `DOCKER_PROJECT_NAME`, its containers and volumes belong to the old default project `laravel-app`. Add `DOCKER_PROJECT_NAME=laravel-app` to `.env` before `make deploy` (`make init` does this automatically while those containers exist); any other name starts a new, empty project.
