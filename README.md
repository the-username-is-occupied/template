<p align="center"><a href="https://laravel.com" target="_blank"><img src="https://raw.githubusercontent.com/laravel/art/master/logo-lockup/5%20SVG/2%20CMYK/1%20Full%20Color/laravel-logolockup-cmyk-red.svg" width="400" alt="Laravel Logo"></a></p>

<h1 align="center"><a href="https://frankenphp.dev"><img src="frankenphp.png" alt="FrankenPHP" width="400"></a></h1>


(http://127.0.0.1/)

Stack:
- Laravel (Octane/FrankenPHP) 
- PostgreSQL
- Redis

Dashboards:
- Pulse (http://127.0.0.1/pulse)
- Horizon (http://127.0.0.1/horizon/dashboard)
- Telescope (http://127.0.0.1/telescope/requests)

# Getting Started

## Настройка окружения

1. Скопируйте файл окружения:

```bash
cp .env.example .env --update=none
```

2. Настройте `COMPOSE_PROJECT_NAME` в `.env`

3. Инициализация проекта:

```bash
make init    # или: task init
```

## Quick Commands

| Command | Description |
|---------|-------------|
| `make dev` | Start dev environment (foreground) |
| `make up` | Start containers in background |
| `make shell` | Open bash inside app container |
| `make test` | Run tests in parallel |
| `make check` | Run all quality checks (lint, rector, phpstan, test, insights) |
| `make fmt` | Fix code style (Pint) |
| `make migrate` | Run database migrations |
| `make logs` | Tail container logs |
| `make infra` | Start only DB + Redis |
| `make prod-build` | Build production image |
| `make deploy` | Run production deployment |

Run `make help` or `task --list` for all available targets.

## Docker — Dev vs Production

```bash
# Development (auto-merges compose.override.yml)
docker compose up

# Production (hardened overlay)
docker compose -f compose.yml -f compose.production.yml up -d
```

# About 



Description
