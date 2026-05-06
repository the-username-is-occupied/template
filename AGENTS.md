# AGENTS.md

## Cursor Cloud specific instructions

### Architecture

Laravel 12 API skeleton running on FrankenPHP (Octane) via Docker Compose with 3 services:
- **app** (`laravelapp-app`): Laravel + FrankenPHP + Supervisor (runs Octane, Horizon, Pulse, scheduler, Vite dev)
- **db** (`laravelapp-db`): PostgreSQL
- **redis** (`laravelapp-redis`): Redis (cache, sessions, queues, Horizon)

### Starting services

```bash
# Start Docker daemon first (required in cloud VM)
dockerd &
# Wait for daemon readiness, then:
cd /workspace && docker compose up -d --build
```

After `docker compose up`, Supervisor inside the app container automatically starts Octane (port 80), Horizon, Pulse worker, scheduler, and Vite dev server (port 5173).

### Running commands inside the container

All artisan/composer/npm/quality commands run **inside** the app container. The Makefile/Taskfile use `docker exec -it`, but for non-interactive (CI/agent) usage, drop `-it`:

```bash
docker exec laravelapp-app <command>
```

### Environment files

- Copy `.env.example` → `.env` and set `APP_NAMESPACE=laravelapp` (used as Docker container name prefix).
- Copy `.env.testing.example` → `.env.testing` for tests.

### Tests

Tests use SQLite in-memory and do **not** require PostgreSQL or Redis:

```bash
docker exec laravelapp-app php artisan cache:clear
docker exec laravelapp-app php artisan test --env=testing --parallel
```

### Lint / Quality checks

See `Makefile` targets (`quality-*`) or `Taskfile.yml` tasks (`quality:*`). Key commands:

| Tool | Command |
|------|---------|
| Pint (style) | `docker exec laravelapp-app vendor/bin/pint --test --config ./pint.json` |
| Rector | `docker exec laravelapp-app vendor/bin/rector process --dry-run` |
| PHPStan | `docker exec laravelapp-app vendor/bin/phpstan analyse -c ./phpstan.neon` |
| PHP Insights | `docker exec laravelapp-app vendor/bin/phpinsights --summary` |

### Known gotchas

- The FrankenPHP base image ships PHP 8.5, which emits deprecation warnings about `PDO::MYSQL_ATTR_SSL_CA`. These are cosmetic and come from Laravel's database config — not from application code.
- `db:seed` will fail with a unique constraint violation if the test user already exists. This is expected on re-runs; the seed is not idempotent.
- The `APP_NAMESPACE` env var is **required** — it's the prefix for all Docker container names (`${APP_NAMESPACE}-app`, `${APP_NAMESPACE}-db`, `${APP_NAMESPACE}-redis`).
