# AGENTS.md

## Cursor Cloud specific instructions

This is a Docker-based Laravel 12 starter kit using FrankenPHP (Octane), PostgreSQL, and Redis.

### Architecture

| Container | Role |
|-----------|------|
| `devenv-app` | Laravel app (FrankenPHP/Octane, Horizon, Pulse, Vite dev server via Supervisor) |
| `devenv-db` | PostgreSQL |
| `devenv-redis` | Redis (cache, queue, sessions) |

The `APP_NAMESPACE` env var is set to `devenv` and prefixes all container names.

### Starting the environment

```bash
cd /workspace
dockerd &>/var/log/dockerd.log &
sleep 3
docker-compose up -d
```

Wait for health checks (~10s). Verify: `docker-compose ps` should show all containers `(healthy)`.

### Running quality checks (inside the app container)

Commands documented in `Makefile.md`. Quick reference:

- Lint (Pint): `docker exec -i devenv-app vendor/bin/pint --test --config ./pint.json`
- Static analysis (PHPStan): `docker exec -i devenv-app vendor/bin/phpstan analyse -c ./phpstan.neon`
- Rector: `docker exec -i devenv-app vendor/bin/rector process --dry-run`
- Insights: `docker exec -i devenv-app vendor/bin/phpinsights --summary --no-interaction`
- Tests: `docker exec -i devenv-app php artisan test --env=testing --parallel`

Tests use SQLite in-memory — no external services needed.

### Gotchas

- The Makefile uses `docker-compose` (hyphenated). A symlink `/usr/local/bin/docker-compose -> /usr/libexec/docker/cli-plugins/docker-compose` is needed when only the Docker Compose plugin is available.
- The Docker image includes PHP 8.3 in its Dockerfile `ARG`, but the base FrankenPHP image may ship a newer PHP. PDO deprecation warnings (`PDO::MYSQL_ATTR_SSL_CA`) are cosmetic and pre-existing.
- The Vite dev server runs inside the container (port 5173). Hot-reload works via the volume mount of the workspace.
- `docker exec` commands must use `-i` (not `-it`) when run non-interactively (CI, scripts).
