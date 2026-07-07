# Laravel Docker Template

Reusable Docker setup for new Laravel projects that run with Laravel Octane and FrankenPHP.

## What is included

- `Dockerfile` for PHP (FrankenPHP), Composer, and Node build assets. PHP and Node versions are configurable through `PHP_VERSION` and `NODE_VERSION` in `.env`.
- `docker-compose.yml` for app, queue, scheduler, PostgreSQL (pgvector image, so the `vector` extension is available when needed), and Redis.
- `docker-compose.override.yml` for local development with bind mounts and Vite.
- `Makefile` with common commands for build, start, logs, tests, dumps, and deploy.
- `.env.docker.example` with Docker-specific variables that `make init` adds to the target project's `.env`.

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
docker compose -f docker-compose.yml -f docker-compose.override.yml run --build --rm --no-deps --entrypoint composer app require laravel/octane --no-scripts
docker compose -f docker-compose.yml -f docker-compose.override.yml run --rm --no-deps --entrypoint php app artisan package:discover --ansi
docker compose -f docker-compose.yml -f docker-compose.override.yml run --rm --no-deps --entrypoint php app artisan octane:install --server=frankenphp
```

Using `--no-scripts` avoids project-specific Composer hooks blocking Octane installation. For example, a project may have `@php artisan boost:update --ansi` in `post-update-cmd`; that command requires Boost to be installed first with `php artisan boost:install`.

## Environment variables

`make init` adds missing variables from `.env.docker.example` to the target project's `.env`. Existing `.env` values are not overwritten.

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
make dump
make import
```

`make dump` writes the app database to `docker/db/dump.sql.gz`. `make import` recreates the database from that file.

## Troubleshooting

If the Vite container restarts with `ENOSPC: System limit for number of file watchers reached`, exclude heavy directories from the Vite watcher in the project's `vite.config.js`:

```js
server: {
    watch: {
        ignored: ['**/vendor/**', '**/storage/**', '**/.git/**'],
    },
},
```

The host inotify watcher limit is shared by all containers, so this shows up when several projects run Vite at the same time. Alternatively raise `fs.inotify.max_user_watches` on the host or set `VITE_USE_POLLING=1` in `.env`.

## Production mode

Set `APP_ENV=production` in `.env` and run:

```bash
make build
```

For deploy on a server:

```bash
make deploy
```
