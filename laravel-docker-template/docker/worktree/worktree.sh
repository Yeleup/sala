#!/usr/bin/env bash
# Git worktree support for this Docker setup (Orca, Claude Code, `git worktree add`).
#
# A worktree gets its own .env (compose project, ports, databases, database role) and a real copy of
# vendor/. Short-lived commands (make test/artisan/composer/shell) run in one-off containers that mount
# the worktree and use the main checkout's PostgreSQL with the worktree's own databases and role;
# `make up` in a worktree starts a fully isolated stack instead.
#
#   setup            write .env, copy vendor/, composer install, create databases, migrate
#   check-env        fail unless .env was generated for this worktree (used by make)
#   ensure-db [db]   create this worktree's databases and database role (used by make test)
#   archive          best-effort teardown before the worktree is deleted; always exits 0
#   prune [--apply]  list (or remove) Docker resources and databases of deleted worktrees
#
# On the main checkout's PostgreSQL the worktree role is not a superuser: it owns its databases and may
# create more (Laravel parallel-test databases), but cannot create untrusted extensions such as pgvector.
# ensure-db installs the extensions of the main database into template1 and into every database it owns.
#
# Requires bash, git >= 2.31, docker compose, flock, ss, realpath, cksum, od.
set -euo pipefail

ENV_FILE="${ENV_FILE:-.env}"
PORT_SLOTS=24

log() { printf '[worktree] %s\n' "$*" >&2; }
die() {
    log "ERROR: $*"
    exit 1
}

# env_get KEY [FILE]: value of KEY with surrounding quotes removed.
env_get() {
    local key="$1" file="${2:-$ENV_FILE}" value
    [ -f "$file" ] || return 0
    value="$(awk -v key="$key" 'index($0, key "=") == 1 { print substr($0, length(key) + 2); exit }' "$file")"
    case "$value" in
        \"*\" | \'*\') value="${value:1:${#value}-2}" ;;
    esac
    printf '%s\n' "$value"
}

git_dir() { git rev-parse --path-format=absolute --git-dir; }
git_common_dir() { git rev-parse --path-format=absolute --git-common-dir; }
is_linked_worktree() { [ "$(git_dir)" != "$(git_common_dir)" ]; }
main_root() { dirname "$(git_common_dir)"; }

db_container() {
    docker ps -q --filter "label=com.docker.compose.project=$1" --filter 'label=com.docker.compose.service=db' \
        --filter 'label=com.docker.compose.oneoff=False' --filter status=running 2>/dev/null | head -n 1 || true
}

# container_env CONTAINER KEY: KEY from the container's environment (empty if unset).
container_env() { docker exec "$1" printenv "$2" 2>/dev/null || true; }

# root_sql CONTAINER DATABASE [ON_ERROR_STOP]: SQL from stdin as the container's bootstrap superuser
# (POSTGRES_USER over the local socket, so no password leaves the container); prints bare rows.
root_sql() {
    timeout "${SQL_TIMEOUT:-60}" docker exec -i "$1" \
        sh -c 'exec psql -X -q -At -v ON_ERROR_STOP="$2" -U "$POSTGRES_USER" -d "$1"' sh "$2" "${3:-1}"
}

slugify() {
    printf '%s' "$1" | LC_ALL=C tr '[:upper:]' '[:lower:]' | LC_ALL=C sed -E 's/[^a-z0-9]+/-/g; s/^-+//; s/-+$//' \
        | cut -c1-20 | sed -E 's/-+$//'
}

random_hex() { od -An -N"$1" -tx1 /dev/urandom | tr -d ' \n'; }

# Ports claimed by the .env of any checkout of this repository, plus every port listening now.
reserved_ports() {
    local dir key
    git worktree list --porcelain | sed -n 's/^worktree //p' | while IFS= read -r dir; do
        for key in APP_PORT FORWARD_DB_PORT VITE_PORT; do
            env_get "$key" "$dir/$ENV_FILE"
        done
    done
    ss -Hltn 2>/dev/null | awk '{ sub(/.*:/, "", $4); print $4 }'
}

# allocate_ports SEED [BASE]: "app db vite" ports from the first free slot of a 100-port block
# (BASE defaults to a block in 20000-29900 derived from SEED, so each repository gets its own block).
allocate_ports() {
    local base="${2:-}" reserved start n slot port
    if [ -z "$base" ]; then
        base=$((20000 + $(printf '%s' "$1" | cksum | cut -d' ' -f1) % 100 * 100))
    fi
    reserved=" $(reserved_ports | sort -un | tr '\n' ' ') "
    start=$(($(printf '%s' "$PWD" | cksum | cut -d' ' -f1) % PORT_SLOTS))
    for ((n = 0; n < PORT_SLOTS; n++)); do
        slot=$(((start + n) % PORT_SLOTS))
        port=$((base + slot * 4))
        case "$reserved" in
            *" $port "* | *" $((port + 1)) "* | *" $((port + 2)) "*) continue ;;
        esac
        printf '%s %s %s\n' "$port" "$((port + 1))" "$((port + 2))"
        return 0
    done
    return 1
}

# render_env SRC KEY=VALUE...: SRC with the given keys replaced in place (missing keys appended).
render_env() {
    local src="$1"
    shift
    awk -v overrides="$(printf '%s\n' "$@")" '
        BEGIN {
            n = split(overrides, lines, "\n")
            for (i = 1; i <= n; i++) {
                if (lines[i] == "") continue
                eq = index(lines[i], "=")
                key = substr(lines[i], 1, eq - 1)
                order[++count] = key
                value[key] = substr(lines[i], eq + 1)
            }
        }
        {
            eq = index($0, "=")
            key = eq > 1 ? substr($0, 1, eq - 1) : ""
            if (key in value) {
                if (!(key in done)) {
                    print key "=" value[key]
                    done[key] = 1
                }
                next
            }
            print
        }
        END {
            header = 0
            for (i = 1; i <= count; i++) {
                key = order[i]
                if (key in done) continue
                if (!header) {
                    print ""
                    print "# Git worktree: generated by docker/worktree/worktree.sh - never copy this file into another checkout"
                    header = 1
                }
                print key "=" value[key]
            }
        }' "$src"
}

write_env() {
    local root="$1" src="$1/$ENV_FILE" main_env main_project main_db id slug db_slug db_prefix project dev_db
    local app_port db_port vite_port tmp
    [ -f "$src" ] || die "$src not found: set up the main checkout first (make init there)."
    main_env="$(env_get APP_ENV "$src")"
    case "$main_env" in
        local | development | testing) ;;
        *) die "Refusing to copy $src (APP_ENV=${main_env:-empty}) into a worktree." ;;
    esac
    [ "$(env_get WORKTREE_MANAGED "$src")" != 1 ] || die "$src is itself a worktree .env."
    main_project="$(env_get DOCKER_PROJECT_NAME "$src")"
    [[ "$main_project" =~ ^[a-z0-9][a-z0-9_-]*$ ]] || die "Invalid DOCKER_PROJECT_NAME '$main_project' in $src."
    main_db="$(env_get DB_DATABASE "$src")"

    id="$(random_hex 4)"
    slug="$(slugify "$(basename "$PWD")")"
    project="${main_project}-wt-${slug:+$slug-}${id}"
    db_prefix="$(printf '%s' "${main_db:-app}" | LC_ALL=C tr -c 'A-Za-z0-9_' '_' | cut -c1-20)"
    db_slug="$(printf '%s' "${slug//-/_}" | cut -c1-16 | sed -E 's/_+$//')"
    dev_db="${db_prefix}_wt_${db_slug:+${db_slug}_}${id}"
    read -r app_port db_port vite_port < <(allocate_ports "$main_project" "$(env_get WORKTREE_PORT_BASE "$src")") \
        || die "No free port slot; set WORKTREE_PORT_BASE in $src."

    tmp="$(git rev-parse --path-format=absolute --git-path worktree-env.tmp)"
    render_env "$src" \
        "WORKTREE_MANAGED=1" \
        "WORKTREE_ID=$id" \
        "WORKTREE_GITDIR=$(git_dir)" \
        "DOCKER_PROJECT_NAME=$project" \
        "DOCKER_INFRA_PROJECT=$main_project" \
        "APP_PORT=$app_port" \
        "APP_URL=http://localhost:$app_port" \
        "FORWARD_DB_PORT=$db_port" \
        "VITE_PORT=$vite_port" \
        "DB_DATABASE=$dev_db" \
        "DB_USERNAME=wt_$id" \
        "DB_PASSWORD=$(random_hex 16)" \
        "DB_TEST_DATABASE=${dev_db}_test" \
        "SESSION_COOKIE=${project//-/_}_session" >"$tmp"
    chmod 600 "$tmp"
    mv "$tmp" "$ENV_FILE"
    log "Wrote $ENV_FILE: project=$project url=http://localhost:$app_port database=$dev_db"
}

# Loads and validates a worktree .env generated by write_env for THIS worktree (dies otherwise).
load_worktree_env() {
    [ -f "$ENV_FILE" ] || die "No $ENV_FILE in this worktree: run make worktree-setup."
    [ "$(env_get WORKTREE_MANAGED)" = 1 ] \
        || die "$ENV_FILE was not generated for this git worktree (a copy of the main .env would take over the main stack). Delete it and run make worktree-setup."
    is_linked_worktree \
        || die "$ENV_FILE was generated for a git worktree, but this is the main checkout. Restore its own .env (make init)."
    [ "$(env_get WORKTREE_GITDIR)" = "$(git_dir)" ] \
        || die "$ENV_FILE was generated for another worktree ($(env_get WORKTREE_GITDIR)). Delete it and run make worktree-setup."

    WT_ID="$(env_get WORKTREE_ID)"
    WT_PROJECT="$(env_get DOCKER_PROJECT_NAME)"
    WT_INFRA="$(env_get DOCKER_INFRA_PROJECT)"
    WT_DB="$(env_get DB_DATABASE)"
    WT_TEST_DB="$(env_get DB_TEST_DATABASE)"
    WT_USER="$(env_get DB_USERNAME)"
    [[ "$WT_ID" =~ ^[0-9a-f]{8}$ ]] || die "Invalid WORKTREE_ID in $ENV_FILE."
    [[ "$WT_INFRA" =~ ^[a-z0-9][a-z0-9_-]*$ ]] || die "Invalid DOCKER_INFRA_PROJECT in $ENV_FILE."
    [[ "$WT_PROJECT" =~ ^${WT_INFRA}-wt-([a-z0-9-]+-)?${WT_ID}$ ]] || die "DOCKER_PROJECT_NAME in $ENV_FILE is not this worktree's project."
    [[ "$WT_DB" =~ ^[A-Za-z0-9_]+_wt_([a-z0-9_]+_)?${WT_ID}$ ]] || die "DB_DATABASE in $ENV_FILE is not this worktree's database."
    [ "$WT_TEST_DB" = "${WT_DB}_test" ] || die "DB_TEST_DATABASE in $ENV_FILE must be ${WT_DB}_test."
    [ "$WT_USER" = "wt_$WT_ID" ] || die "DB_USERNAME in $ENV_FILE must be wt_$WT_ID."
}

# True when vendor/ shares its files with the main checkout: a symlink (which containers cannot follow)
# or hardlinks from `cp -al` (through which composer rewrites the main checkout's autoloader in place).
vendor_shared_with_main() {
    local root="$1"
    [ -e vendor ] || return 1
    [ ! -L vendor ] || return 0
    [ -f vendor/autoload.php ] && [ vendor/autoload.php -ef "$root/vendor/autoload.php" ]
}

copy_vendor() {
    local root="$1" tmp="vendor.tmp.$$"
    if [ -e vendor ] && ! vendor_shared_with_main "$root"; then
        return 0
    fi
    [ -f "$root/vendor/autoload.php" ] \
        || die "vendor/ is shared with the main checkout and the main checkout has no vendor/ to copy. Delete vendor/ and run make composer composer_args=install."
    if [ -e vendor ]; then
        log "Replacing a vendor/ that shares files with the main checkout (symlink or cp -al hardlinks: composer would write through into it)."
    else
        log "Copying vendor/ from the main checkout (a real copy: symlinks dangle in containers, hardlinks write through to main)."
    fi
    rm -rf "$tmp"
    cp -a --reflink=auto "$root/vendor" "$tmp"
    rm -rf vendor
    mv "$tmp" vendor
}

cmd_setup() {
    local root
    is_linked_worktree || die "Not a linked git worktree. In the main checkout use make init."
    root="$(main_root)"
    [ "$(realpath "$root")" != "$(pwd -P)" ] || die "Refusing to run in the main checkout."

    # Serialises port allocation between worktrees that are set up at the same time.
    exec 9>"$(git_common_dir)/worktree-setup.lock"
    flock -w 120 9 || die "Another worktree setup holds $(git_common_dir)/worktree-setup.lock."
    if [ -f "$ENV_FILE" ] && (load_worktree_env) 2>/dev/null; then
        load_worktree_env
        log "Keeping existing $ENV_FILE ($WT_PROJECT)."
    else
        # A copy of the main .env (or another worktree's) would take over that checkout's stack: keep it
        # for reference, but never use it here.
        if [ -e "$ENV_FILE" ]; then
            mv "$ENV_FILE" "$ENV_FILE.pre-worktree"
            log "Moved $ENV_FILE (not generated for this worktree) to $ENV_FILE.pre-worktree."
        fi
        write_env "$root"
        load_worktree_env
    fi
    exec 9>&-

    copy_vendor "$root"

    # Lets one-off containers and an opt-in `make up` start without a build; `make build` rebuilds it.
    if ! docker image inspect "$WT_PROJECT-app" >/dev/null 2>&1 && docker image inspect "$WT_INFRA-app" >/dev/null 2>&1; then
        docker tag "$WT_INFRA-app" "$WT_PROJECT-app"
    fi

    make --no-print-directory composer composer_args="install --no-interaction --prefer-dist --no-progress"
    grep -Eq '^APP_KEY=.+' "$ENV_FILE" || make --no-print-directory key-generate

    if make --no-print-directory -s ensure-db; then
        make --no-print-directory artisan artisan_args="migrate --force --no-interaction" \
            || log "WARNING: migrations failed; fix them and run: make artisan artisan_args=migrate"
    else
        log "WARNING: no running database, migrations skipped. Start the main stack (or make db-up here), then run: make artisan artisan_args=migrate"
    fi

    if [ ! -e .codex/config.toml ] && [ -f "$root/.codex/config.toml" ]; then
        mkdir -p .codex
        cp "$root/.codex/config.toml" .codex/config.toml
    fi

    log "Ready ($WT_PROJECT): make test | make artisan artisan_args=... | make up (own stack at $(env_get APP_URL)) | make down"
}

# ensure_db_script MANAGE_ROLE PASSWORD DATABASE...: a POSIX sh script for the database container that
# creates the missing databases (owned by the worktree role). With MANAGE_ROLE=1 (the main checkout's
# PostgreSQL) it also creates the role and installs the main database's extensions into template1 and
# into every database the role owns, because the role may not create untrusted extensions itself.
# Values are validated by the caller and travel on stdin, never on a command line.
ensure_db_script() {
    local manage="$1" password="$2"
    shift 2
    printf 'ROLE=%s\nPASSWORD=%s\nMANAGE_ROLE=%s\nDATABASES="%s"\n' "$WT_USER" "$password" "$manage" "$*"
    cat <<'SH'
set -eu
export PGOPTIONS='-c client_min_messages=warning'
q() { psql -X -q -At -v ON_ERROR_STOP=1 -U "$POSTGRES_USER" "$@"; }
if [ "$MANAGE_ROLE" = 1 ]; then
    q -d postgres <<SQL
SELECT 'CREATE ROLE "$ROLE"' WHERE NOT EXISTS (SELECT FROM pg_roles WHERE rolname = '$ROLE')\gexec
ALTER ROLE "$ROLE" WITH LOGIN CREATEDB NOSUPERUSER NOCREATEROLE NOREPLICATION NOBYPASSRLS PASSWORD '$PASSWORD';
SQL
fi
for db in $DATABASES; do
    if [ -z "$(q -d postgres -c "SELECT 1 FROM pg_database WHERE datname = '$db'")" ]; then
        q -d postgres -c "CREATE DATABASE \"$db\" OWNER \"$ROLE\""
    fi
done
if [ "$MANAGE_ROLE" = 1 ]; then
    # template1 is what the role's own CREATE DATABASE (Laravel parallel tests) copies, and databases
    # created earlier may predate an extension of the main database.
    extensions="$(q -d "$POSTGRES_DB" -c "SELECT extname FROM pg_extension WHERE extname <> 'plpgsql' ORDER BY 1")"
    owned="$(q -d postgres -c "SELECT datname FROM pg_database WHERE datallowconn AND NOT datistemplate AND datdba = (SELECT oid FROM pg_roles WHERE rolname = '$ROLE')")"
    targets=
    [ -z "$extensions" ] || targets="template1 $DATABASES $owned"
    for db in $(printf '%s\n' $targets | sort -u); do
        for extension in $extensions; do
            printf 'CREATE EXTENSION IF NOT EXISTS "%s";\n' "$extension"
        # A database the role owns can disappear in between (parallel tests recreate them).
        done | q -d "$db" || true
    done
fi
SH
}

cmd_check_env() {
    load_worktree_env
    ! vendor_shared_with_main "$(main_root)" \
        || die "vendor/ is shared with the main checkout (symlink, or hardlinks from cp -al): composer here would rewrite the main checkout's vendor. Run make worktree-setup."
}

cmd_ensure_db() {
    local extra="${1:-}" project container superuser main_db main_test db password
    load_worktree_env
    project="${RUN_PROJECT:-$WT_PROJECT}"
    [ "$project" = "$WT_PROJECT" ] || [ "$project" = "$WT_INFRA" ] || die "Unexpected compose project '$project'."
    container="$(db_container "$project")"
    [ -n "$container" ] \
        || die "No running database for compose project '$project': start the main stack (make up in the main checkout) or run make db-up here."
    if [ -n "$extra" ]; then
        [[ "$extra" =~ ^[A-Za-z0-9_]{1,54}$ ]] && [[ "$extra" == "${WT_DB}"_* ]] \
            || die "test_database must start with ${WT_DB}_ and be at most 54 characters (PostgreSQL truncates at 63, and parallel tests append _test_<n>)."
    fi
    superuser="$(container_env "$container" POSTGRES_USER)"

    # Concurrent runs would race on CREATE ROLE / CREATE EXTENSION and on template1.
    exec 8>"$(git_common_dir)/worktree-db.lock"
    flock -w 120 8 || die "Another worktree holds $(git_common_dir)/worktree-db.lock."

    if [ "$project" = "$WT_PROJECT" ]; then
        # This worktree's own stack (make up / make db-up): the worktree role is its bootstrap superuser.
        [ "$superuser" = "$WT_USER" ] || die "The $project database runs as '$superuser', not $WT_USER: recreate it (make down-volumes, make db-up)."
        ensure_db_script 0 '' "$WT_DB" "$WT_TEST_DB" $extra | timeout "${SQL_TIMEOUT:-60}" docker exec -i "$container" sh -s
        return 0
    fi

    main_db="$(container_env "$container" POSTGRES_DB)"
    main_test="$(container_env "$container" POSTGRES_TEST_DATABASE)"
    [ -n "$superuser" ] && [ -n "$main_db" ] || die "Cannot read POSTGRES_USER / POSTGRES_DB of the $project database container."
    [ "$WT_USER" != "$superuser" ] || die "$ENV_FILE uses $project's superuser '$superuser'."
    for db in "$WT_DB" "$WT_TEST_DB" $extra; do
        [ "$db" != "$main_db" ] && [ "$db" != "$main_test" ] || die "$ENV_FILE points at $project's own database '$db'."
    done
    password="$(env_get DB_PASSWORD)"
    [[ "$password" =~ ^[0-9a-f]{32}$ ]] || die "DB_PASSWORD in $ENV_FILE was not generated by make worktree-setup."

    ensure_db_script 1 "$password" "$WT_DB" "$WT_TEST_DB" $extra | timeout "${SQL_TIMEOUT:-60}" docker exec -i "$container" sh -s
}

# remove_project PROJECT DIR: containers, volumes, networks and image tag of a compose project started from DIR.
remove_project() {
    local project="$1" here="$2" dir
    while IFS= read -r dir; do
        if [ -n "$dir" ] && [ "$(realpath -m "$dir")" != "$here" ]; then
            log "Compose project $project has containers from $dir: not removing it."
            return 0
        fi
    done < <(docker ps -a --filter "label=com.docker.compose.project=$project" \
        --format '{{.Label "com.docker.compose.project.working_dir"}}' | sort -u)
    docker ps -aq --filter "label=com.docker.compose.project=$project" | xargs -r docker rm -f >/dev/null
    docker volume ls -q --filter "label=com.docker.compose.project=$project" | xargs -r docker volume rm >/dev/null
    docker network ls -q --filter "label=com.docker.compose.project=$project" | xargs -r docker network rm >/dev/null
    docker image rm "$project-app" >/dev/null 2>&1
    return 0
}

# remove_oneoffs PROJECT DIR: one-off containers (make test, Boost MCP, ...) started from DIR in PROJECT.
remove_oneoffs() {
    local project="$1" here="$2" id dir
    docker ps -a --filter "label=com.docker.compose.project=$project" --filter 'label=com.docker.compose.oneoff=True' \
        --format '{{.ID}} {{.Label "com.docker.compose.project.working_dir"}}' | while read -r id dir; do
        if [ "$(realpath -m "$dir")" = "$here" ]; then
            docker rm -f "$id" >/dev/null
        fi
    done
    return 0
}

drop_worktree_databases() {
    local container="$1" superuser main_db main_test db
    superuser="$(container_env "$container" POSTGRES_USER)"
    main_db="$(container_env "$container" POSTGRES_DB)"
    main_test="$(container_env "$container" POSTGRES_TEST_DATABASE)"
    [ -n "$superuser" ] && [ -n "$main_db" ] && [ "$WT_USER" != "$superuser" ] || return 0
    {
        printf 'SELECT datname FROM pg_database WHERE NOT datistemplate;\n' | root_sql "$container" postgres | while IFS= read -r db; do
            [ "$db" = "$WT_DB" ] || [[ "$db" == "${WT_DB}"_* ]] || continue
            [ "$db" != "$main_db" ] && [ "$db" != "$main_test" ] || continue
            printf 'DROP DATABASE IF EXISTS "%s" WITH (FORCE);\n' "$db"
        done
        printf 'DROP ROLE IF EXISTS "%s";\n' "$WT_USER"
    } | root_sql "$container" postgres 0
}

cmd_archive() {
    local here container
    set +e
    [ -f "$ENV_FILE" ] || {
        log "No $ENV_FILE: nothing to clean up."
        return 0
    }
    if ! (load_worktree_env) 2>/dev/null; then
        log "$ENV_FILE was not generated for this worktree: skipping cleanup."
        return 0
    fi
    load_worktree_env
    here="$(pwd -P)"

    # Root-owned container output (vite build, recordings) would block deleting the worktree.
    if [ -n "$(timeout 30 find . -xdev -user 0 -print -quit 2>/dev/null)" ]; then
        timeout 120 docker run --rm -v "$here:/worktree" node:22-alpine chown -R "$(id -u):$(id -g)" /worktree
    fi

    remove_project "$WT_PROJECT" "$here"
    remove_oneoffs "$WT_INFRA" "$here"

    container="$(db_container "$WT_INFRA")"
    if [ -n "$container" ]; then
        drop_worktree_databases "$container"
    else
        log "Databases ${WT_DB}* and role $WT_USER not dropped ($WT_INFRA database is not running): run make worktree-prune in the main checkout later."
    fi
    log "Cleaned up $WT_PROJECT."
    return 0
}

cmd_prune() {
    local apply="${1:-}" root main_project main_db db_prefix live_ids dir id project container db role token live protected key
    root="$(main_root)"
    main_project="$(env_get DOCKER_PROJECT_NAME "$root/$ENV_FILE")"
    main_db="$(env_get DB_DATABASE "$root/$ENV_FILE")"
    [[ "$main_project" =~ ^[a-z0-9][a-z0-9_-]*$ ]] || die "DOCKER_PROJECT_NAME is missing or invalid in $root/$ENV_FILE."
    db_prefix="$(printf '%s' "${main_db:-app}" | LC_ALL=C tr -c 'A-Za-z0-9_' '_' | cut -c1-20)"

    # Setup writes a worktree's .env under this lock before creating any of its resources, so holding it
    # for the whole prune keeps a worktree that is being set up from looking stale.
    exec 9>"$(git_common_dir)/worktree-setup.lock"
    flock -w 120 9 || die "A worktree setup holds $(git_common_dir)/worktree-setup.lock."

    live_ids=" "
    while IFS= read -r dir; do
        id="$(env_get WORKTREE_ID "$dir/$ENV_FILE")"
        [ -z "$id" ] || live_ids+="$id "
    done < <(git worktree list --porcelain | sed -n 's/^worktree //p')

    {
        docker ps -a --format '{{.Label "com.docker.compose.project"}}'
        docker volume ls --format '{{.Label "com.docker.compose.project"}}'
        docker network ls --format '{{.Label "com.docker.compose.project"}}'
        docker image ls --format '{{.Repository}}' | sed -n 's/-app$//p'
    } | sort -u | while IFS= read -r project; do
        [[ "$project" =~ ^${main_project}-wt-([a-z0-9-]+-)?([0-9a-f]{8})$ ]] || continue
        [[ "$live_ids" != *" ${BASH_REMATCH[2]} "* ]] || continue
        log "stale compose project: $project"
        [ "$apply" = --apply ] || continue
        docker ps -aq --filter "label=com.docker.compose.project=$project" | xargs -r docker rm -f >/dev/null
        docker volume ls -q --filter "label=com.docker.compose.project=$project" | xargs -r docker volume rm >/dev/null
        docker network ls -q --filter "label=com.docker.compose.project=$project" | xargs -r docker network rm >/dev/null
        docker image rm "$project-app" >/dev/null 2>&1 || true
    done

    docker ps -a --filter "label=com.docker.compose.project=$main_project" --filter 'label=com.docker.compose.oneoff=True' \
        --format '{{.ID}} {{.Label "com.docker.compose.project.working_dir"}}' | while read -r id dir; do
        [ ! -d "$dir" ] || continue
        log "stale one-off container $id (from deleted $dir)"
        [ "$apply" != --apply ] || docker rm -f "$id" >/dev/null
    done

    container="$(db_container "$main_project")"
    if [ -z "$container" ]; then
        log "$main_project database is not running: databases and roles not checked."
    else
        # The main checkout's own databases and role are never stale, whatever their names look like.
        protected=" $main_db $(env_get DB_TEST_DATABASE "$root/$ENV_FILE") $(env_get DB_USERNAME "$root/$ENV_FILE")"
        for key in POSTGRES_DB POSTGRES_TEST_DATABASE POSTGRES_USER; do
            protected+=" $(container_env "$container" "$key")"
        done
        protected+=" "

        printf 'SELECT datname FROM pg_database WHERE NOT datistemplate;\n' | root_sql "$container" postgres | while IFS= read -r db; do
            # Only names that worktree-setup generates: <prefix>_wt_[<slug>_]<id>[_<suffix>].
            [[ "$db" =~ ^${db_prefix}_wt_([a-z0-9_]+_)?[0-9a-f]{8}(_[A-Za-z0-9_]+)?$ ]] || continue
            [[ "$protected" != *" $db "* ]] || continue
            live=0
            for token in ${db//_/ }; do
                [[ "$live_ids" != *" $token "* ]] || live=1
            done
            [ "$live" = 0 ] || continue
            log "stale database: $db"
            [ "$apply" != --apply ] || printf 'DROP DATABASE IF EXISTS "%s" WITH (FORCE);\n' "$db" | root_sql "$container" postgres
        done
        printf "SELECT rolname FROM pg_roles WHERE rolname ~ '^wt_[0-9a-f]{8}\$';\n" | root_sql "$container" postgres | while IFS= read -r role; do
            [[ "$live_ids" != *" ${role#wt_} "* ]] || continue
            [[ "$protected" != *" $role "* ]] || continue
            log "stale database role: $role"
            [ "$apply" != --apply ] || printf 'DROP ROLE IF EXISTS "%s";\n' "$role" | root_sql "$container" postgres
        done
    fi
    [ "$apply" = --apply ] || log "Dry run. Remove with: make worktree-prune CONFIRM=yes"
}

cd "$(git rev-parse --show-toplevel)"
case "${1:-}" in
    setup) cmd_setup ;;
    check-env) cmd_check_env ;;
    ensure-db) cmd_ensure_db "${2:-}" ;;
    archive) cmd_archive || true ;;
    prune) cmd_prune "${2:-}" ;;
    *) die "usage: $0 setup|check-env|ensure-db [database]|archive|prune [--apply]" ;;
esac
