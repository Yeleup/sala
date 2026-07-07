#!/bin/sh
set -eu

umask 0002

cd /var/www/html

ensure_application_key() {
    if [ -n "${APP_KEY:-}" ]; then
        return
    fi

    if [ -f .env ] && grep -Eq '^APP_KEY=.+$' .env; then
        return
    fi

    echo "APP_KEY is not set. Run 'make key-generate' or set APP_KEY in .env." >&2
    exit 1
}

prepare_application() {
    mkdir -p \
        bootstrap/cache \
        storage/app/public \
        storage/framework/cache \
        storage/framework/sessions \
        storage/framework/views \
        storage/logs

    if [ "$(id -u)" = "0" ]; then
        chown -R www-data:www-data \
            bootstrap/cache \
            storage/app/public \
            storage/framework/cache \
            storage/framework/sessions \
            storage/framework/views \
            storage/logs
        chmod -R ug+rwX \
            bootstrap/cache \
            storage/app/public \
            storage/framework/cache \
            storage/framework/sessions \
            storage/framework/views \
            storage/logs
    else
        chmod -R ug+rwX \
            bootstrap/cache \
            storage/app/public \
            storage/framework/cache \
            storage/framework/sessions \
            storage/framework/views \
            storage/logs >/dev/null 2>&1 || true
    fi

    ln -sfn /var/www/html/storage/app/public /var/www/html/public/storage
}

wait_for_database() {
    if [ "${WAIT_FOR_DATABASE:-1}" != "1" ]; then
        return
    fi

    php -r '
        $driver = getenv("DB_CONNECTION") ?: "pgsql";
        $host = getenv("DB_HOST") ?: "db";
        $port = getenv("DB_PORT") ?: ($driver === "pgsql" ? "5432" : "3306");
        $user = getenv("DB_USERNAME") ?: "root";
        $password = getenv("DB_PASSWORD") ?: "";
        $dsn = $driver === "pgsql"
            ? "pgsql:host={$host};port={$port};dbname=" . (getenv("DB_DATABASE") ?: "postgres")
            : "mysql:host={$host};port={$port}";

        for ($attempt = 0; $attempt < 30; $attempt++) {
            try {
                new PDO(
                    $dsn,
                    $user,
                    $password,
                    [
                        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                        PDO::ATTR_TIMEOUT => 2,
                    ],
                );

                exit(0);
            } catch (Throwable $exception) {
                fwrite(STDERR, "Waiting for database...\n");
                sleep(2);
            }
        }

        fwrite(STDERR, "Database connection timed out.\n");
        exit(1);
    '
}

clear_bootstrap_cache_if_needed() {
    if [ "${APP_OPTIMIZE:-0}" = "1" ]; then
        return
    fi

    rm -f bootstrap/cache/*.php
}

run_migrations_if_needed() {
    if [ "${RUN_MIGRATIONS:-0}" = "1" ]; then
        php artisan migrate --force --no-interaction
    fi
}

optimize_application_if_needed() {
    if [ "${APP_OPTIMIZE:-0}" = "1" ]; then
        php artisan optimize --no-interaction
    fi
}

ensure_application_key
prepare_application
wait_for_database
clear_bootstrap_cache_if_needed
run_migrations_if_needed
optimize_application_if_needed

exec "$@"
