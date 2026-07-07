SHELL := /bin/bash

ENV_FILE ?= .env
FILE_DOCKER_PROJECT_NAME := $(strip $(shell sed -n 's/^DOCKER_PROJECT_NAME=//p' $(ENV_FILE) 2>/dev/null | head -n 1))
FILE_APP_ENV := $(strip $(shell sed -n 's/^APP_ENV=//p' $(ENV_FILE) 2>/dev/null | head -n 1))
ifneq ($(origin DOCKER_PROJECT_NAME),command line)
DOCKER_PROJECT_NAME := $(or $(FILE_DOCKER_PROJECT_NAME),laravel-app)
endif
ifneq ($(origin APP_ENV),command line)
APP_ENV := $(FILE_APP_ENV)
endif

COMPOSE_ENV_FILE := $(if $(wildcard $(ENV_FILE)),--env-file $(ENV_FILE),)
LOAD_ENV := set -a; if [ -f "$(ENV_FILE)" ]; then source "$(ENV_FILE)"; fi; set +a;
COMPOSE_BASE := docker compose -p $(DOCKER_PROJECT_NAME) $(COMPOSE_ENV_FILE)
LOCAL_COMPOSE := $(COMPOSE_BASE) -f docker-compose.yml -f docker-compose.override.yml
PROD_COMPOSE := $(COMPOSE_BASE) -f docker-compose.yml
IS_PRODUCTION := $(filter production,$(APP_ENV))
ENV_COMPOSE := $(if $(IS_PRODUCTION),$(PROD_COMPOSE),$(LOCAL_COMPOSE))
ENV_ENSURE_VENDOR := $(if $(IS_PRODUCTION),,ensure-vendor)
ENV_ENSURE_NODE_MODULES := $(if $(IS_PRODUCTION),,ensure-node-modules)

test_args ?= --compact
artisan_args ?= list
composer_args ?= --version
npm_args ?= --version
dump_file ?= docker/db/dump.sql.gz
storage_dump_file ?= docker/db/storage.tar.gz
storage_dir ?= storage/app

.PHONY: help init ports ensure-vendor ensure-node-modules build up down down-volumes restart logs ps shell artisan composer npm key-show key-generate dump import dump-media import-media test test-worktree deploy

help:
	@printf '%s\n' \
		'make init                # create .env, add missing Docker vars, and generate APP_KEY' \
		'make ports PORT_BASE=8080 # write APP_PORT, FORWARD_DB_PORT (+1), VITE_PORT (+2) into .env' \
		'make build               # build and start using APP_ENV from .env' \
		'make up                  # start without rebuilding using APP_ENV from .env' \
		'make down                # stop the stack' \
		'make down-volumes        # stop the stack and delete named volumes' \
		'make restart             # restart the stack' \
		'make logs                # follow logs' \
		'make ps                  # show container status' \
		'make shell               # open a shell in the app container' \
		'make artisan artisan_args="route:list" # run artisan in the app container' \
		'make composer composer_args="install"  # run composer in the app container' \
		'make npm npm_args="run build"          # run npm in the vite container' \
		'make key-show            # print a generated APP_KEY' \
		'make key-generate        # write APP_KEY into .env' \
		'make dump                # export the app database to docker/db/dump.sql.gz' \
		'make import              # recreate the app database from docker/db/dump.sql.gz' \
		'make dump-media          # export storage/app to docker/db/storage.tar.gz' \
		'make import-media        # replace storage/app from docker/db/storage.tar.gz' \
		'make test                # run Laravel tests in the app container' \
		'make test-worktree       # run tests from a git worktree in a one-off app container' \
		'make deploy              # production: pull, build, migrate, and restart queue workers'

init:
	@if [ ! -f "$(ENV_FILE)" ]; then \
		test -f .env.example || (echo '.env.example not found.' >&2; exit 1); \
		cp .env.example "$(ENV_FILE)"; \
	fi
	@if [ -f .env.docker.example ]; then \
		added_header=0; \
		while IFS= read -r line || [ -n "$$line" ]; do \
			case "$$line" in \
				''|'#'*) continue ;; \
			esac; \
			key="$${line%%=*}"; \
			if [ -n "$$key" ] && ! grep -Eq "^$${key}=" "$(ENV_FILE)"; then \
				if [ "$$added_header" = "0" ]; then \
					printf '\n# Docker\n' >> "$(ENV_FILE)"; \
					added_header=1; \
				fi; \
				printf '%s\n' "$$line" >> "$(ENV_FILE)"; \
			fi; \
		done < .env.docker.example; \
	fi
	$(MAKE) ensure-vendor
	@if ! grep -Eq '^APP_KEY=base64:.+' "$(ENV_FILE)"; then \
		$(MAKE) key-generate; \
	fi
	$(MAKE) ensure-node-modules

ports:
	@test -n "$(PORT_BASE)" || (echo 'Usage: make ports PORT_BASE=8080' >&2; exit 1)
	@test -f "$(ENV_FILE)" || (echo '$(ENV_FILE) not found. Run make init first.' >&2; exit 1)
	@app_port=$$(($(PORT_BASE))); \
	db_port=$$(($(PORT_BASE) + 1)); \
	vite_port=$$(($(PORT_BASE) + 2)); \
	for pair in "APP_PORT=$$app_port" "FORWARD_DB_PORT=$$db_port" "VITE_PORT=$$vite_port"; do \
		key=$${pair%%=*}; \
		if grep -Eq "^$$key=" "$(ENV_FILE)"; then \
			sed -i "s|^$$key=.*|$$pair|" "$(ENV_FILE)"; \
		else \
			printf '%s\n' "$$pair" >> "$(ENV_FILE)"; \
		fi; \
	done; \
	if grep -Eq '^APP_URL=https?://localhost' "$(ENV_FILE)"; then \
		sed -i "s|^APP_URL=.*|APP_URL=http://localhost:$$app_port|" "$(ENV_FILE)"; \
	fi; \
	echo "APP_PORT=$$app_port FORWARD_DB_PORT=$$db_port VITE_PORT=$$vite_port"

ensure-vendor:
	@if [ ! -f vendor/autoload.php ]; then \
		echo 'vendor/autoload.php is missing. Installing Composer dependencies...'; \
		$(LOAD_ENV) $(LOCAL_COMPOSE) run --build --rm --no-deps --entrypoint sh app -lc 'composer install --no-interaction --prefer-dist --no-progress'; \
	fi

ensure-node-modules:
	@if [ -f package.json ]; then \
		$(LOAD_ENV) $(LOCAL_COMPOSE) run --rm --no-deps --entrypoint sh vite -lc '\
			if [ ! -x node_modules/.bin/vite ]; then \
				echo "node_modules is missing. Installing Node dependencies..."; \
				if [ -f package-lock.json ]; then npm ci --no-fund --no-audit; else npm install --no-fund --no-audit; fi; \
			fi'; \
	fi

build: $(ENV_ENSURE_VENDOR) $(ENV_ENSURE_NODE_MODULES)
	$(LOAD_ENV) $(ENV_COMPOSE) up -d --build --remove-orphans

up: $(ENV_ENSURE_VENDOR) $(ENV_ENSURE_NODE_MODULES)
	$(LOAD_ENV) $(ENV_COMPOSE) up -d --remove-orphans

down:
	$(LOAD_ENV) $(ENV_COMPOSE) down --remove-orphans

down-volumes:
	$(LOAD_ENV) $(ENV_COMPOSE) down -v --remove-orphans

restart: down up

logs:
	$(LOAD_ENV) $(ENV_COMPOSE) logs -f

ps:
	$(LOAD_ENV) $(ENV_COMPOSE) ps

shell:
	$(LOAD_ENV) $(ENV_COMPOSE) exec app sh

artisan:
	$(LOAD_ENV) $(ENV_COMPOSE) exec app php artisan $(artisan_args)

composer:
	$(LOAD_ENV) $(LOCAL_COMPOSE) run --rm --no-deps --entrypoint composer app $(composer_args)

npm:
	$(LOAD_ENV) $(LOCAL_COMPOSE) run --rm --no-deps --entrypoint npm vite $(npm_args)

key-show:
	$(LOAD_ENV) $(LOCAL_COMPOSE) run --rm --no-deps --entrypoint php app artisan key:generate --show --no-interaction

key-generate:
	$(LOAD_ENV) $(LOCAL_COMPOSE) run --rm --no-deps --entrypoint php app artisan key:generate --no-interaction

dump:
	@mkdir -p "$(dir $(dump_file))"
	$(LOAD_ENV) $(ENV_COMPOSE) exec -T db env PGPASSWORD="$$DB_PASSWORD" pg_dump --no-owner --no-acl -U"$$DB_USERNAME" "$$DB_DATABASE" | gzip > "$(dump_file)"

import:
	@test "$(APP_ENV)" != "production" || (echo 'Refusing to run import with APP_ENV=production.' >&2; exit 1)
	@test -f "$(dump_file)" || (echo 'Dump file not found: $(dump_file)' >&2; exit 1)
	@gzip -t "$(dump_file)" || (echo 'Invalid gzip archive: $(dump_file)' >&2; exit 1)
	$(LOAD_ENV) $(ENV_COMPOSE) exec -T db env PGPASSWORD="$$DB_PASSWORD" dropdb --if-exists --force -U"$$DB_USERNAME" --maintenance-db=postgres "$$DB_DATABASE"
	$(LOAD_ENV) $(ENV_COMPOSE) exec -T db env PGPASSWORD="$$DB_PASSWORD" createdb -U"$$DB_USERNAME" --maintenance-db=postgres "$$DB_DATABASE"
	$(LOAD_ENV) gunzip -c "$(dump_file)" | $(ENV_COMPOSE) exec -T db env PGPASSWORD="$$DB_PASSWORD" psql -v ON_ERROR_STOP=1 -U"$$DB_USERNAME" "$$DB_DATABASE"

dump-media:
	@test -d "$(storage_dir)" || (echo 'Storage directory not found: $(storage_dir)' >&2; exit 1)
	@mkdir -p "$(dir $(storage_dump_file))"
	tar -czf "$(storage_dump_file)" "$(storage_dir)"

import-media:
	@test -f "$(storage_dump_file)" || (echo 'Storage archive not found: $(storage_dump_file)' >&2; exit 1)
	@tmp_storage_dir="$$(mktemp -d)"; \
	trap 'rm -rf "$$tmp_storage_dir"' EXIT; \
	tar -xzf "$(storage_dump_file)" -C "$$tmp_storage_dir"; \
	test -d "$$tmp_storage_dir/$(storage_dir)" || (echo 'Storage archive does not contain $(storage_dir)' >&2; exit 1); \
	rm -rf "$(storage_dir)"; \
	mkdir -p "$(dir $(storage_dir))"; \
	mv "$$tmp_storage_dir/$(storage_dir)" "$(storage_dir)"; \
	trap - EXIT

test:
	@test "$(APP_ENV)" != "production" || (echo 'Refusing to run tests in production.' >&2; exit 1)
	$(LOAD_ENV) $(LOCAL_COMPOSE) exec -T app env \
		APP_ENV=testing \
		APP_DEBUG=true \
		DB_CONNECTION=pgsql \
		DB_HOST=db \
		DB_PORT=5432 \
		DB_DATABASE="$${DB_TEST_DATABASE:-laravel_app_testing}" \
		DB_USERNAME="$$DB_USERNAME" \
		DB_PASSWORD="$$DB_PASSWORD" \
		php artisan test $(test_args)

test-worktree:
	@test "$(APP_ENV)" != "production" || (echo 'Refusing to run tests in production.' >&2; exit 1)
	$(LOAD_ENV) $(LOCAL_COMPOSE) run --rm --no-deps --entrypoint env app \
		APP_ENV=testing \
		APP_DEBUG=true \
		DB_CONNECTION=pgsql \
		DB_HOST=db \
		DB_PORT=5432 \
		DB_DATABASE="$${DB_TEST_DATABASE:-laravel_app_testing}" \
		DB_USERNAME="$$DB_USERNAME" \
		DB_PASSWORD="$$DB_PASSWORD" \
		php artisan test $(test_args)

deploy:
	git pull
	$(LOAD_ENV) $(PROD_COMPOSE) up -d --build --remove-orphans
	$(LOAD_ENV) $(PROD_COMPOSE) exec -T app php artisan migrate --force --no-interaction
	$(LOAD_ENV) $(PROD_COMPOSE) exec -T app php artisan queue:restart
