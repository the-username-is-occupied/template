# Architecture

## Stack

| Слой | Технология |
|---|---|
| Backend API | Laravel 12 |
| NotebookLM executor | FastAPI (notebooklm-py) |
| TG scraper | FastAPI (custom Python) |
| YouTube metadata | YouTubeService (Google YouTube Data API v3) |
| Frontend | Vue 3 + Quasar |
| Realtime / SSE | FrankenPHP (Laravel Octane + Mercure Hub) |
| Queue / cache / locks | Redis |
| Database | PostgreSQL |
| Infrastructure | Docker Compose |

---

## Service Boundaries

```
┌──────────────────────────────────────────────────────┐
│                   Laravel (core)                      │
│  Auth · Business logic · Account pool · Schedulers   │
└──────┬──────────────────┬───────────────┬────────────┘
       │                  │               │
  ┌────▼────┐     ┌───────▼──────┐  ┌────▼───────┐
  │ FastAPI │     │  TG Scraper  │  │  YTService │
  │  (NLM)  │     │  FastAPI     │  │            │
  └────┬────┘     └───────┬──────┘  └─────┬──────┘
       │                  │               │
       └──────────────────┼───────────────┘
                          │
                ┌─────────▼──────────┐
                │  Redis + Postgres  │
                └────────────────────┘
```

**Laravel** — core API: аутентификация, бизнес-логика, захват аккаунтов из пула, диспетчеризация задач, schedulers обновлений.

**FastAPI NLM** — Держит пул инициализированных `NotebookLMClient` в памяти, маршрутизирует по `account_id`. Не принимает решений о выборе аккаунта.

**TG Scraper** — принимает `{channel_id, last_post_id}`, возвращает новые посты.

**YouTubeService** — обёртка над Google YouTube Data API v3. Описание в docs/services. Видео затем добавляются в NotebookLM как YouTube-источники.

---

## NotebookLM Account Tier Limits

> Лимиты **на один технический Google-аккаунт**. Синхронизируются вручную в таблице `account_tier_limits`.

| Tier | Блокноты | Источников/блокнот | Чатов/день | Аудио/день |
|---|---|---|---|---|
| Free | 100 | 50 | 50 | 3 |
| Plus | 200 | 100 | 200 | 6 |
| Pro | 500 | 300 | 500 | 20 |
| Ultra | 500 | 500 | 2500 | 100 |

При достижении лимита источников в блокноте — апгрейд аккаунта вручную.

---

## Key Architectural Decisions

| # | Решение | Обоснование |
|---|---|---|
| 1 | One knowledge base = one NotebookLM notebook | Простота MVP; cross-notebook ask не реализован |
| 2 | Ask capacity = сумма лимитов аккаунтов, шарящих ноутбук | Все ноутбуки создаются как public viewer shared |
| 3 | Chat history управляет Eolithic, не NLM | История инжектируется в каждый ask как текстовый префикс |
| 4 | Full MD bundles — write-once; Delta bundles — overwrite until ~490k | Full бандлы не переиндексируются. Живые обновления и остатки накапливаются в delta_bundle, который перезаписывается в NLM до достижения лимита ~490k символов, после чего freeze'ится и создаётся новый delta_bundle. |
| 5 | Account selection for ask — MIN(chats_today) | Равномерное распределение нагрузки по аккаунтам |

---

## Cross-Cutting Concerns

- **Request ID:** middleware добавляет `X-Request-ID` к каждому запросу (сквозной трейсинг)
- **Request log:** timestamp, duration (total / Laravel / FastAPI), success flag
- **Ask success:** = ответ возвращён, независимо от качества содержания
- **SSE:** долгие операции (индексация, ask streaming) через Mercure Hub
- **Error handler:** общий перехватчик ошибок, отвечает с кодом и сообщением
