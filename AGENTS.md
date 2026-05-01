# AGENTS.md

## Cursor Cloud specific instructions

This is a Docker-first Laravel 12 project running on FrankenPHP/Octane. All development commands run inside containers.

### Quick reference

| Action | Command |
|--------|---------|
| Start containers | `docker compose up -d` (from `/workspace`) |
| Rebuild containers | `docker compose up -d --build` |
| Full init | `make init` (requires `docker-compose` alias or use `COMPOSE="docker compose" make init`) |
| Shell into app | `docker exec -it laravel-app /bin/bash` |
| Run tests | `docker exec laravel-app php artisan test --env=testing --parallel` |
| Lint (Pint) | `docker exec laravel-app vendor/bin/pint --test --config ./pint.json` |
| PHPStan | `docker exec laravel-app vendor/bin/phpstan analyse -c ./phpstan.neon` |
| Migrations | `docker exec laravel-app php artisan migrate` |

### Key gotchas

- **Docker daemon must be started manually** in cloud VMs: `dockerd &>/var/log/dockerd.log &` before any `docker compose` command.
- **The Makefile uses `docker-compose`** (hyphenated, standalone binary) which is not installed by default. Use `docker compose` (plugin) directly, or override via `COMPOSE="docker compose" make <target>`.
- **`.env` must have `APP_NAMESPACE` set** (e.g. `laravel`) — it's used as container name prefix. Copy `.env.example` to `.env` and `.env.testing.example` to `.env.testing` before starting.
- **PHP 8.5 deprecation warnings** about `PDO::MYSQL_ATTR_SSL_CA` appear in HTTP responses — these are cosmetic, from the bundled PHP version in FrankenPHP image.
- **Tests use SQLite in-memory** and do NOT require PostgreSQL/Redis. They run with `--env=testing` which loads `.env.testing`.
- **Node version in the running container** comes from apt (`nodejs` package) and may be older than the v22 used in the Dockerfile build stage. The Vite dev server inside the container may show engine warnings but works.
- **Container names** follow the pattern `${APP_NAMESPACE}-app`, `${APP_NAMESPACE}-db`, `${APP_NAMESPACE}-redis`.
- **Supervisor** manages Octane, Horizon, Pulse, scheduler, and Vite dev server inside the app container.
