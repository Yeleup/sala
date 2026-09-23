# Git Worktrees (Orca, Claude Code, Codex)

Parallel agents work in git worktrees: Orca creates them in `~/orca/workspaces/<repo>/<name>`, Claude Code in `.claude/worktrees/<name>`. You are in a worktree when `git rev-parse --path-format=absolute --git-dir` differs from `git rev-parse --path-format=absolute --git-common-dir` (without `--path-format=absolute` they also differ in subdirectories of the main checkout).

- The main checkout's running stack (its `APP_URL`, Vite port, `<project>-*` containers and `docker exec` into them) serves the MAIN checkout's code and database. Never use it to verify or change a worktree.
- A worktree gets its own `.env` from `make worktree-setup` (Orca runs it through `orca.yaml` before the agent starts): `WORKTREE_MANAGED=1`, its own `DOCKER_PROJECT_NAME`, ports, databases and database role. Never copy or symlink the main `.env`, never run `make init` in a worktree, never point `DOCKER_PROJECT_NAME` or `docker compose -p` at the main project.
- If `.env` or `vendor/` is missing, or make says the `.env` "was not generated for this git worktree" or that `vendor/` is shared with the main checkout, run `make worktree-setup` and nothing else: it moves a copied `.env` aside to `.env.pre-worktree`, writes this worktree's own, and replaces a `vendor/` that shares files with the main checkout.
- `make test`, `make artisan`, `make composer`, `make pint` and `make shell` run in one-off containers that mount the current checkout, so they test and change THIS worktree.
- `make test` uses the worktree's own test database on the main checkout's PostgreSQL, so parallel agents do not collide. "No running database": ask the user to start the main stack, or run `make db-up` (only this worktree's db and redis).
- After adding migrations: `make artisan artisan_args="migrate"` (the worktree's own database). Boost MCP answers for this worktree's code and database.
- On the main checkout's PostgreSQL the worktree role is not a superuser and gets only the extensions the main database already has. A migration that adds another untrusted extension fails there with "permission denied to create extension": run `make db-up` (this worktree's own PostgreSQL, where its role is the superuser) and test against it.
- UI checks: `make up` starts an isolated stack at `APP_URL` from the worktree's `.env` with a freshly migrated, empty database (`make artisan artisan_args="db:seed"` to fill it); `make down` when finished.
- After changing `Dockerfile` or `docker/app/*`: `make build` in the worktree (one-off containers otherwise use the main checkout's image).
- Deleting a worktree: `make worktree-archive` in it, or `orca worktree rm --run-hooks ...` (without `--run-hooks` Orca skips the cleanup and leaves the databases, role and containers behind; `make worktree-prune CONFIRM=yes` in the main checkout removes them later).
- After a branch is merged, migrations do not run by themselves in the main checkout: `make artisan artisan_args="migrate"` there.
