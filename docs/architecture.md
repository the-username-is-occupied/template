# Architecture

## Stack

| Слой | Технология |
|---|---|
| Backend API | Laravel 12 |
| NotebookLM executor | FastAPI (notebooklm-py) |
| TG scraper | FastAPI (custom Python) |
| YouTube metadata | FastAPI (yt-dlp) |
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
  │ FastAPI │     │  TG Scraper  │  │  yt-dlp    │
  │  (NLM)  │     │  FastAPI     │  │  FastAPI   │
  └────┬────┘     └───────┬──────┘  └────┬───────┘
       │                  │               │
       └──────────────────┼───────────────┘
                          │
                ┌─────────▼──────────┐
                │  Redis + Postgres  │
                └────────────────────┘
```

**Laravel** — core API: аутентификация, бизнес-логика, захват аккаунтов из пула, диспетчеризация задач, schedulers обновлений.

**FastAPI NLM** — stateless executor. Держит пул инициализированных `NotebookLMClient` в памяти, маршрутизирует по `account_id`. Не принимает решений о выборе аккаунта.

**TG Scraper** — принимает `{channel_id, last_post_id}`, возвращает новые посты.

**yt-dlp service** — принимает URL канала/плейлиста, возвращает список video URLs. Видео затем добавляются в NotebookLM как YouTube-источники.

---

## NotebookLM Account Tier Limits

> Лимиты **на один технический Google-аккаунт**. Синхронизируются вручную в таблице `account_tier_limits`.

| Tier | Блокноты | Источников/блокнот | Чатов/день | Аудио/день |
|---|---|---|---|---|
| Free | 100 | 50 | 50 | 3 |
| ~1000₽/мес | 200 | 100 | 200 | 6 |
| ~2000₽/мес | 500 | 300 | 500 | 20 |
| Top | 500 | 500 | 2500 | 100 |

При достижении лимита источников в блокноте — апгрейд аккаунта вручную.

---

## Key Architectural Decisions

| # | Решение | Обоснование |
|---|---|---|
| 1 | One knowledge base = one NotebookLM notebook | Простота MVP; cross-notebook ask не реализован |
| 2 | Ask capacity = сумма лимитов аккаунтов, шарящих ноутбук | Все ноутбуки создаются как public viewer shared |
| 3 | Chat history управляет Eolithic, не NLM | История инжектируется в каждый ask как текстовый префикс (Variant B) |
| 4 | MD bundles — write-once | Исключает переиндексации; новый контент = новый бандл |
| 5 | Account selection for ask — MIN(chats_today) | Равномерное распределение нагрузки по аккаунтам |

---

## Cross-Cutting Concerns

- **Request ID:** middleware добавляет `X-Request-ID` к каждому запросу (сквозной трейсинг)
- **Request log:** timestamp, duration (total / Laravel / FastAPI), success flag
- **Ask success:** = ответ возвращён, независимо от качества содержания
- **SSE:** долгие операции (индексация, ask streaming) через Mercure Hub
- **Error handler:** общий перехватчик ошибок, отвечает с кодом и сообщением
