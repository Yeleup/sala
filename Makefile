SHELL := /bin/bash

ENV_FILE ?= .env
# $(call env_value,KEY): KEY from $(ENV_FILE) without surrounding quotes.
env_value = $(strip $(shell sed -n 's/^$(1)=//p' $(ENV_FILE) 2>/dev/null | head -n 1 | sed -E "s/^([\"'])(.*)\1$$/\2/"))
# $(call running_db,PROJECT): id of the running db container of a compose project (empty if none).
running_db = $(if $(1),$(shell docker ps -q --filter 'label=com.docker.compose.project=$(1)' --filter 'label=com.docker.compose.service=db' --filter 'label=com.docker.compose.oneoff=False' --filter status=running 2>/dev/null | head -n 1))

# Non-empty in a linked git worktree (Orca, Claude Code, `git worktree add`), empty in the main checkout.
IS_WORKTREE := $(shell [ "$$(git rev-parse --path-format=absolute --git-dir 2>/dev/null)" != "$$(git rev-parse --path-format=absolute --git-common-dir 2>/dev/null)" ] && echo 1)

ifneq ($(origin DOCKER_PROJECT_NAME),command line)
DOCKER_PROJECT_NAME := $(call env_value,DOCKER_PROJECT_NAME)
endif
ifneq ($(origin APP_ENV),command line)
APP_ENV := $(call env_value,APP_ENV)
endif
DOCKER_INFRA_PROJECT := $(if $(IS_WORKTREE),$(call env_value,DOCKER_INFRA_PROJECT))

# Compose project whose network one-off containers join: this checkout's own, unless this is a git
# worktree whose own database is not running while the main checkout's is. The worktree then uses its
# own databases and database role on the main checkout's PostgreSQL.
RUN_PROJECT := $(DOCKER_PROJECT_NAME)
ifneq ($(DOCKER_INFRA_PROJECT),)
ifeq ($(call running_db,$(DOCKER_PROJECT_NAME)),)
ifneq ($(call running_db,$(DOCKER_INFRA_PROJECT)),)
RUN_PROJECT := $(DOCKER_INFRA_PROJECT)
endif
endif
endif

COMPOSE_ENV_FILE := $(if $(wildcard $(ENV_FILE)),--env-file $(ENV_FILE),)
LOAD_ENV := set -a; if [ -f "$(ENV_FILE)" ]; then source "$(ENV_FILE)"; fi; set +a; export DOCKER_PROJECT_NAME='$(DOCKER_PROJECT_NAME)';
COMPOSE_BASE := docker compose -p $(DOCKER_PROJECT_NAME) $(COMPOSE_ENV_FILE)
LOCAL_COMPOSE := $(COMPOSE_BASE) -f docker-compose.yml -f docker-compose.override.yml
PROD_COMPOSE := $(COMPOSE_BASE) -f docker-compose.yml
IS_PRODUCTION := $(filter production,$(APP_ENV))
ENV_COMPOSE := $(if $(IS_PRODUCTION),$(PROD_COMPOSE),$(LOCAL_COMPOSE))
ENV_ENSURE_VENDOR := $(if $(IS_PRODUCTION),,ensure-vendor)
ENV_ENSURE_NODE_MODULES := $(if $(IS_PRODUCTION),,ensure-node-modules)
# One-off containers always bind-mount THIS checkout. RUN_PROJECT may be the main checkout's project:
# use it only with `run --rm --no-deps`, never with up/down/exec. On the main checkout's network a
# worktree keeps cache, queue and sessions away from the shared Redis and queue worker. XDG_CONFIG_HOME:
# the image points it at /config, which the non-root user cannot write (tinker/psysh fails there).
RUN_ISOLATION := $(if $(filter-out $(DOCKER_PROJECT_NAME),$(RUN_PROJECT)),-e CACHE_STORE=file -e QUEUE_CONNECTION=sync -e SESSION_DRIVER=file,)
RUN_APP_BASE := $(LOAD_ENV) docker compose -p $(RUN_PROJECT) $(COMPOSE_ENV_FILE) -f docker-compose.yml -f docker-compose.override.yml run --rm --no-deps -e XDG_CONFIG_HOME=/tmp
RUN_APP := $(RUN_APP_BASE) $(RUN_ISOLATION)
NO_DB_HINT := $(if $(DOCKER_INFRA_PROJECT),start the main checkout stack (make up there) or this worktree's own database (make db-up),start it with make up or make db-up)
WORKTREE_SH := bash docker/worktree/worktree.sh
# Compose project name that older versions of this setup used when DOCKER_PROJECT_NAME was empty.
LEGACY_PROJECT_NAME := laravel-app

test_args ?= --compact
test_database ?=
artisan_args ?= list
composer_args ?= --version
npm_args ?= --version
dump_file ?= docker/db/dump.sql.gz
storage_dump_file ?= docker/db/storage.tar.gz
storage_dir ?= storage/app

.PHONY: help init ports require-env require-not-production require-test-database guard-owner require-db ensure-db ensure-vendor ensure-node-modules build up db-up down down-volumes restart logs ps shell artisan composer npm pint key-show key-generate dump import dump-media import-media test test-worktree boost-mcp worktree-setup worktree-archive worktree-prune demo-video deploy

help:
	@printf '%s\n' \
		'make init                # main checkout: create .env, add missing Docker vars, and generate APP_KEY' \
		'make worktree-setup      # git worktree: own .env (project, ports, databases), vendor, migrations' \
		'make ports PORT_BASE=8080 # write APP_PORT, FORWARD_DB_PORT (+1), VITE_PORT (+2) into .env' \
		'make build               # build and start this checkout'"'"'s stack using APP_ENV from .env' \
		'make up                  # start this checkout'"'"'s stack without rebuilding' \
		'make db-up               # start only this checkout'"'"'s db and redis (enough for make test)' \
		'make down                # stop the stack' \
		'make down-volumes        # stop the stack and delete named volumes' \
		'make restart             # restart the stack' \
		'make logs                # follow logs' \
		'make ps                  # show container status' \
		'make shell               # shell in a one-off app container that mounts this checkout' \
		'make artisan artisan_args="route:list" # artisan in a one-off app container' \
		'make composer composer_args="install"  # composer in a one-off app container' \
		'make npm npm_args="run build"          # npm in the vite container' \
		'make pint                # Pint on uncommitted PHP files in a one-off app container' \
		'make key-show            # print a generated APP_KEY' \
		'make key-generate        # write APP_KEY into .env' \
		'make dump                # export the app database to docker/db/dump.sql.gz' \
		'make import              # recreate the app database from docker/db/dump.sql.gz' \
		'make dump-media          # export storage/app to docker/db/storage.tar.gz' \
		'make import-media        # replace storage/app from docker/db/storage.tar.gz' \
		'make test                # run this checkout'"'"'s tests in a one-off container (test_args=, test_database=)' \
		'make worktree-archive    # git worktree: remove its containers, volumes, databases and database role' \
		'make worktree-prune      # list leftovers of deleted git worktrees (CONFIRM=yes removes them)' \
		'make demo-video          # record the operator demo video with narration' \
		'make deploy              # production: pull, build, migrate, and restart queue workers'

require-env:
	@if [ -z "$(DOCKER_PROJECT_NAME)" ]; then \
		echo 'DOCKER_PROJECT_NAME is not set in $(ENV_FILE). Main checkout: make init. Git worktree: make worktree-setup.' >&2; \
		if [ -n "$$(docker ps -aq --filter 'label=com.docker.compose.project=$(LEGACY_PROJECT_NAME)' --filter 'label=com.docker.compose.project.working_dir=$(CURDIR)' 2>/dev/null)" ]; then \
			echo 'This checkout already runs containers under the old default project name: add DOCKER_PROJECT_NAME=$(LEGACY_PROJECT_NAME) to $(ENV_FILE) to keep them and their volumes.' >&2; \
		fi; \
		exit 1; \
	fi
	@if [ -n "$(IS_WORKTREE)" ] || [ "$(call env_value,WORKTREE_MANAGED)" = 1 ]; then \
		ENV_FILE='$(ENV_FILE)' $(WORKTREE_SH) check-env; \
	fi

require-not-production:
	@test -z "$(IS_PRODUCTION)" || (echo 'Refusing to run this target with APP_ENV=production.' >&2; exit 1)

# test_database must stay inside this checkout's test databases, so tests never wipe a development one.
require-test-database:
	@db='$(test_database)'; \
	if [ -n "$$db" ]; then \
		$(LOAD_ENV) \
		if [ -z "$$DB_TEST_DATABASE" ]; then \
			echo 'DB_TEST_DATABASE is not set in $(ENV_FILE): refusing test_database=$(test_database).' >&2; \
			exit 1; \
		fi; \
		case "$$db" in \
			"$$DB_TEST_DATABASE" | "$$DB_TEST_DATABASE"_*) ;; \
			*) echo "test_database must be $$DB_TEST_DATABASE or start with $${DB_TEST_DATABASE}_, not $$db." >&2; exit 1 ;; \
		esac; \
		if [ $${#db} -gt 54 ]; then \
			echo 'test_database must be at most 54 characters: PostgreSQL truncates names at 63 and parallel tests append _test_<n>.' >&2; \
			exit 1; \
		fi; \
	fi

# Refuse to change a compose project whose containers were created from another directory.
guard-owner: require-env
	@docker ps -a --filter 'label=com.docker.compose.project=$(DOCKER_PROJECT_NAME)' --filter 'label=com.docker.compose.oneoff=False' \
		--format '{{.Label "com.docker.compose.project.working_dir"}}' | sort -u | while IFS= read -r dir; do \
		if [ -n "$$dir" ] && [ "$$(realpath -m "$$dir")" != "$$(realpath -m "$(CURDIR)")" ]; then \
			echo "Refusing: compose project '$(DOCKER_PROJECT_NAME)' belongs to $$dir, not $(CURDIR)." >&2; \
			echo 'Every checkout needs its own DOCKER_PROJECT_NAME (git worktrees: make worktree-setup).' >&2; \
			exit 1; \
		fi; \
	done

require-db: require-env
	@if [ -z "$(call running_db,$(RUN_PROJECT))" ]; then \
		echo "No running database for compose project '$(RUN_PROJECT)': $(NO_DB_HINT)." >&2; \
		exit 1; \
	fi

# Creates this git worktree's database role and databases when a database is running (a no-op in the main
# checkout), so artisan, tests and Boost MCP still find them after the main stack was recreated.
ensure-db: require-env
ifneq ($(DOCKER_INFRA_PROJECT),)
	@if [ -n "$(call running_db,$(RUN_PROJECT))" ]; then \
		RUN_PROJECT='$(RUN_PROJECT)' ENV_FILE='$(ENV_FILE)' $(WORKTREE_SH) ensure-db $(test_database); \
	fi
endif

init:
	@if [ -n "$(IS_WORKTREE)" ]; then \
		echo 'This is a git worktree: run make worktree-setup (make init would reuse the main checkout'"'"'s project name, ports and databases).' >&2; \
		exit 1; \
	fi
	@if [ ! -f "$(ENV_FILE)" ]; then \
		test -f .env.example || (echo '.env.example not found.' >&2; exit 1); \
		cp .env.example "$(ENV_FILE)"; \
	fi
	@if ! grep -Eq '^DOCKER_PROJECT_NAME=.+' "$(ENV_FILE)" \
		&& [ -n "$$(docker ps -aq --filter 'label=com.docker.compose.project=$(LEGACY_PROJECT_NAME)' --filter 'label=com.docker.compose.project.working_dir=$(CURDIR)' 2>/dev/null)" ]; then \
		sed -i '/^DOCKER_PROJECT_NAME=/d' "$(ENV_FILE)"; \
		printf '\nDOCKER_PROJECT_NAME=%s\n' '$(LEGACY_PROJECT_NAME)' >> "$(ENV_FILE)"; \
		echo 'Kept DOCKER_PROJECT_NAME=$(LEGACY_PROJECT_NAME): this checkout already runs containers under that old default name.'; \
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
	@if ! grep -Eq '^DOCKER_PROJECT_NAME=.+' "$(ENV_FILE)"; then \
		name="$$(basename "$(CURDIR)" | LC_ALL=C tr '[:upper:]' '[:lower:]' | LC_ALL=C sed -E 's/[^a-z0-9]+/-/g; s/^-+//; s/-+$$//')"; \
		name="$${name:-app}"; \
		sed -i '/^DOCKER_PROJECT_NAME=/d' "$(ENV_FILE)"; \
		printf 'DOCKER_PROJECT_NAME=%s\n' "$$name" >> "$(ENV_FILE)"; \
		echo "DOCKER_PROJECT_NAME=$$name written to $(ENV_FILE)."; \
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

ensure-vendor: require-env
	@if [ -L vendor ]; then \
		echo 'vendor/ is a symlink: containers only see this checkout. Delete it and run make ensure-vendor.' >&2; \
		exit 1; \
	fi
	@if [ ! -f vendor/autoload.php ]; then \
		echo 'vendor/autoload.php is missing. Installing Composer dependencies...'; \
		$(RUN_APP) --entrypoint sh app -lc 'composer install --no-interaction --prefer-dist --no-progress'; \
	fi

ensure-node-modules: require-env
	@if [ -f package.json ]; then \
		$(LOAD_ENV) $(LOCAL_COMPOSE) run --rm --no-deps --entrypoint sh vite -lc '\
			if [ ! -x node_modules/.bin/vite ]; then \
				echo "node_modules is missing. Installing Node dependencies..."; \
				if [ -f package-lock.json ]; then npm ci --no-fund --no-audit; else npm install --no-fund --no-audit; fi; \
			fi'; \
	fi

build: guard-owner $(ENV_ENSURE_VENDOR) $(ENV_ENSURE_NODE_MODULES)
	$(LOAD_ENV) $(ENV_COMPOSE) up -d --build --remove-orphans

up: guard-owner $(ENV_ENSURE_VENDOR) $(ENV_ENSURE_NODE_MODULES)
	$(LOAD_ENV) $(ENV_COMPOSE) up -d --remove-orphans

db-up: require-not-production guard-owner
	$(LOAD_ENV) $(LOCAL_COMPOSE) up -d --wait db redis

# No --remove-orphans: one-off containers of this project's git worktrees (their running tests and Boost
# MCP servers) are orphans of this compose file. `up` and `build` still remove stale service containers.
down: guard-owner
	$(LOAD_ENV) $(ENV_COMPOSE) down

down-volumes: guard-owner
	$(LOAD_ENV) $(ENV_COMPOSE) down -v

restart: down up

logs: require-env
	$(LOAD_ENV) $(ENV_COMPOSE) logs -f

ps: require-env
	$(LOAD_ENV) $(ENV_COMPOSE) ps

shell: require-env ensure-db
ifeq ($(IS_PRODUCTION),)
	$(RUN_APP) --entrypoint sh app
else
	$(LOAD_ENV) $(PROD_COMPOSE) exec app sh
endif

artisan: require-env ensure-db
ifeq ($(IS_PRODUCTION),)
	$(RUN_APP) --entrypoint php app artisan $(artisan_args)
else
	$(LOAD_ENV) $(PROD_COMPOSE) exec app php artisan $(artisan_args)
endif

composer: require-env
	$(RUN_APP) --entrypoint composer app $(composer_args)

npm: require-env ensure-node-modules
	$(LOAD_ENV) $(LOCAL_COMPOSE) run --rm --no-deps --entrypoint npm vite $(npm_args)

# Pint on this checkout's uncommitted PHP files: git lists them on the host (`pint --dirty` needs git,
# which the app image does not have).
pint: require-env
	@files="$$(git status --porcelain --untracked-files=all -- '*.php' | awk '{ print $$NF }' | sort -u | while IFS= read -r file; do [ ! -f "$$file" ] || printf '%s\n' "$$file"; done)"; \
	if [ -z "$$files" ]; then echo 'No uncommitted PHP files.'; exit 0; fi; \
	$(RUN_APP) -T --entrypoint php app vendor/bin/pint --format agent $$files

key-show: require-env
	$(RUN_APP) --entrypoint php app artisan key:generate --show --no-interaction

key-generate: require-env
	$(RUN_APP) --entrypoint php app artisan key:generate --no-interaction

dump: guard-owner
	@mkdir -p "$(dir $(dump_file))"
	$(LOAD_ENV) $(ENV_COMPOSE) exec -T db env PGPASSWORD="$$DB_PASSWORD" pg_dump --no-owner --no-acl -U"$$DB_USERNAME" "$$DB_DATABASE" | gzip > "$(dump_file)"

import: require-not-production guard-owner
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

test: require-not-production require-test-database require-db $(ENV_ENSURE_VENDOR) ensure-db
	$(RUN_APP_BASE) -T \
		-e APP_ENV=testing \
		-e APP_DEBUG=true \
		-e DB_CONNECTION=pgsql \
		-e DB_HOST=db \
		-e DB_PORT=5432 \
		-e DB_DATABASE="$(or $(test_database),$${DB_TEST_DATABASE:-laravel_app_testing})" \
		-e QUEUE_CONNECTION=sync \
		-e CACHE_STORE=array \
		-e SESSION_DRIVER=array \
		--entrypoint php app artisan test $(test_args)

# Former name of make test for git worktrees: make test now runs the current checkout everywhere.
test-worktree: test

# Laravel Boost MCP stdio server for this checkout: nothing but the server may write to stdout, so a
# missing image (whose build output compose prints there) is an error instead of a build.
boost-mcp: require-not-production require-env ensure-db
	@docker image inspect '$(DOCKER_PROJECT_NAME)-app' >/dev/null 2>&1 \
		|| { echo 'Image $(DOCKER_PROJECT_NAME)-app is missing: run make build first.' >&2; exit 1; }
	@$(RUN_APP) -T --entrypoint php app artisan boost:mcp

worktree-setup:
	@$(WORKTREE_SH) setup

worktree-archive:
	@$(WORKTREE_SH) archive

worktree-prune:
	@$(WORKTREE_SH) prune $(if $(filter yes,$(CONFIRM)),--apply,)

demo-video: require-not-production require-env
	DEMO_APP_CONTAINER=$(DOCKER_PROJECT_NAME)-app-1 \
	tools/demo-video/run.sh

deploy: guard-owner
	@test -z "$(IS_WORKTREE)" || (echo 'Refusing to deploy from a git worktree.' >&2; exit 1)
	git pull
	$(LOAD_ENV) $(PROD_COMPOSE) up -d --build --remove-orphans
	$(LOAD_ENV) $(PROD_COMPOSE) exec -T app php artisan migrate --force --no-interaction
	$(LOAD_ENV) $(PROD_COMPOSE) exec -T app php artisan queue:restart
