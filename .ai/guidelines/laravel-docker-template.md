# Laravel Docker Template

The `laravel-docker-template` directory is the reusable source template for this project's Docker setup.

When changing Docker, runtime, or local development configuration in the project root, make the equivalent generic change in `laravel-docker-template` during the same task.

Application containers load the project's `.env` through `env_file`, so new environment variables reach `app`, queue, and scheduler containers after a recreate/redeploy without compose changes. Only add a variable to the `x-app-environment` block when its value must be overridden inside Docker (for example `DB_HOST`, `REDIS_HOST`). Never copy project-specific variables or secrets into `laravel-docker-template`.

This applies to:

- `.dockerignore`
- `.env.docker.example`
- `Dockerfile`
- `Makefile`
- `docker-compose.yml`
- `docker-compose.override.yml`
- `docker/app/*`
- Docker-related README instructions

Keep the template reusable. Do not copy project-specific secrets, local machine paths, app names, generated files, or one-off values into `laravel-docker-template` unless the change is intentionally part of the reusable template.
