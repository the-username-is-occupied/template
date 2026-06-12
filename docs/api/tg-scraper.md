# TG Scraper API (FastAPI)

Асинхронный скрапер публичных Telegram-каналов через `t.me/s/`.

- **Хост внутри сети:** `http://telegramm:8000`
- **Проброшенный порт:** `8020`
- **Документация:** `/docs` (Swagger), `/redoc` (ReDoc)

---

## Endpoints

### `GET /status`

Возвращает текущий статус парсера: занят или свободен.

**Response `200`:**

```json
{
  "busy": false,
  "channel": null,
  "content_source_id": null,
  "started_at": null
}
```

---

### `POST /scrape`

Запускает асинхронный парсинг канала в фоновом режиме. Результаты отправляются на `hook_url`.

**Request body:**

| Поле | Тип | Обязательный | Описание |
|---|---|---|---|
| `content_source_id` | string | да | ID источника контента — передаётся в каждый хук |
| `channel` | string | да | Имя канала без `@`, например: `habr_com` |
| `limit` | int | нет | Максимальное количество постов. `0` = все посты |
| `from_id` | int | нет | Начать с этого post ID и идти назад |
| `to_id` | int | нет | Остановиться на этом post ID (включительно) |
| `from_date` | date | нет | Начать с этой даты и идти назад (`YYYY-MM-DD`) |
| `to_date` | date | нет | Остановиться на этой дате (`YYYY-MM-DD`) |
| `workers` | int | нет | Количество параллельных воркеров (1–5). По умолчанию `3` |
| `chunk_limit` | int | нет | Количество постов в одном вызове хука. По умолчанию `2000` |
| `hook_url` | string | да | URL хука, куда отправляются результаты парсинга |

**Response `202` (Accepted):**

```json
{
  "status": "started",
  "channel": "habr_com",
  "content_source_id": "source_42"
}
```

**Response `409` (Conflict)** — если парсер уже занят:

```json
{
  "detail": {
    "error": "busy",
    "message": "Scraping is already in progress",
    "channel": "some_channel",
    "started_at": "2026-06-01T10:00:00"
  }
}
```

**Хуки (callback'и):**

В процессе парсинга на `hook_url` отправляются POST-запросы:

- `action=upload` — очередная пачка постов и извлечённых ссылок
- `action=done` — парсинг завершён, содержит `response_time` в мс

**Формат payload для `action=upload`:**

```json
{
  "action": "upload",
  "content_source_id": "source_42",
  "posts": [
    {
      "id": 12345,
      "url": "https://t.me/habr_com/12345",
      "date": "2026-06-01T12:00:00",
      "text": "Текст поста...",
      "text_html": "<b>Текст</b> поста...",
      "links": ["https://example.com"],
      "views": "15K",
      "type": "text",
      "forwarded_from": null,
      "poll": null,
      "reactions": [
        {"emoji": "👍", "count": "42"}
      ]
    }
  ]
}
```
> ⚠️ **Важно:** `tg-scrapper` самостоятельно извлекает ссылки из текста постов и присылает их в поле `links`. Backend не занимается извлечением ссылок из текста, а только обрабатывает (дедуплицирует и сохраняет) уже извлечённые ссылки.

---

### `GET /channel/{channel}`

Возвращает мета-информацию о публичном Telegram-канале: название, описание, количество подписчиков, аватар.

**Query parameters:**

| Параметр | Тип | Обязательный | Описание |
|---|---|---|---|
| `channel` | string | да (path) | Имя канала без `@` |
| `content_source_id` | string | нет | Опциональный ID, будет включён в ответ |

**Response `200`:**

```json
{
  "channel": "habr_com",
  "title": "Хабр",
  "description": "Лучшие статьи Хабра",
  "members": "250K",
  "avatar_url": "https://cdn.telegram.org/...",
  "content_source_id": "source_42"
}
```

**Response `404`:** канал не найден или недоступен.

---

### `GET /post/{channel}/{post_id}`

Возвращает полные данные одного поста по имени канала и ID поста.

Включает текст, форматирование, ссылки, реакции, опрос (если есть).

**Response `200`:**

```json
{
  "id": 12345,
  "url": "https://t.me/habr_com/12345",
  "date": "2026-06-01T12:00:00",
  "text": "Текст поста...",
  "text_html": "<b>Текст</b> поста...",
  "links": ["https://example.com"],
  "views": "15K",
  "type": "text",
  "forwarded_from": null,
  "poll": null,
  "reactions": [
    {"emoji": "👍", "count": "42"}
  ]
}
```

**Response `404`:** пост не найден.

---

## Конфигурация Laravel

`config/tg-scraper.php`:

```php
'tg_scraper' => [
    'base_url' => env('TG_SCRAPER_BASE_URL', 'http://telegramm:8000'),
],
```

## Docker

- **Image:** `./docker/tg/Dockerfile` (python:3.11-slim)
- **Сервер:** uvicorn, порт `8000` внутри контейнера
- **Проброс портов:** `8020:8000`
- **Сеть:** `backend`
- **DNS:** 8.8.8.8, 1.1.1.1