# syntax=docker/dockerfile:1.7

ARG PHP_VERSION=8.4
ARG NODE_VERSION=22

FROM dunglas/frankenphp:1-php${PHP_VERSION}-alpine AS php-base

RUN install-php-extensions \
        bcmath \
        exif \
        gd \
        intl \
        mbstring \
        opcache \
        pcntl \
        pdo_pgsql \
        redis \
        zip

COPY docker/app/php.ini /usr/local/etc/php/conf.d/zz-app.ini

WORKDIR /var/www/html

FROM php-base AS composer-base

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

RUN apk add --no-cache \
        git \
        unzip \
        zip

FROM composer-base AS vendor-prod

COPY composer.json composer.lock ./

RUN composer install \
    --no-dev \
    --no-interaction \
    --no-progress \
    --prefer-dist \
    --optimize-autoloader \
    --no-scripts

FROM node:${NODE_VERSION}-alpine AS frontend

WORKDIR /app

COPY . .

RUN if [ -f package-lock.json ]; then \
        npm ci; \
    elif [ -f package.json ]; then \
        npm install; \
    fi \
    && if [ -f package.json ]; then \
        npm run build; \
    else \
        mkdir -p public/build; \
    fi

FROM php-base AS app

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

COPY . .
COPY --from=vendor-prod /var/www/html/vendor /var/www/html/vendor

RUN mkdir -p \
        bootstrap/cache \
        storage/app/public \
        storage/framework/cache \
        storage/framework/sessions \
        storage/framework/views \
        storage/logs \
    && composer dump-autoload \
        --no-dev \
        --no-interaction \
        --no-scripts \
        --optimize \
    && php artisan package:discover --ansi \
    && rm /usr/local/bin/composer

COPY --from=frontend /app/public/build /var/www/html/public/build
COPY docker/app/entrypoint.sh /usr/local/bin/docker-entrypoint
COPY docker/app/healthcheck-http.sh /usr/local/bin/docker-healthcheck-http

RUN chmod +x /usr/local/bin/docker-entrypoint \
    /usr/local/bin/docker-healthcheck-http \
    && mkdir -p \
        bootstrap/cache \
        storage/app/public \
        storage/framework/cache \
        storage/framework/sessions \
        storage/framework/views \
        storage/logs \
    && ln -sfn /var/www/html/storage/app/public /var/www/html/public/storage

EXPOSE 8000

ENTRYPOINT ["docker-entrypoint"]
CMD ["sh", "-lc", "exec php artisan octane:frankenphp --host=0.0.0.0 --port=8000 --admin-port=${OCTANE_ADMIN_PORT:-2019} --workers=${OCTANE_WORKERS:-auto} --max-requests=${OCTANE_MAX_REQUESTS:-500}"]

FROM php-base AS app-dev

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
COPY docker/app/entrypoint.sh /usr/local/bin/docker-entrypoint
COPY docker/app/healthcheck-http.sh /usr/local/bin/docker-healthcheck-http

RUN chmod +x /usr/local/bin/docker-entrypoint \
    /usr/local/bin/docker-healthcheck-http

EXPOSE 8000

ENTRYPOINT ["docker-entrypoint"]
CMD ["sh", "-lc", "exec php artisan octane:frankenphp --host=0.0.0.0 --port=8000 --admin-port=${OCTANE_ADMIN_PORT:-2019} --workers=${OCTANE_WORKERS:-auto} --max-requests=${OCTANE_MAX_REQUESTS:-500}"]
