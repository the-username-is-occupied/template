# YT Scraper API (FastAPI)

> ⚠️ **DEPRECATED:** На данный момент сервис отключён. Вместо него работает `App\Services\YouTubeService` (обёртка над Google YouTube Data API v3). Документация оставлена для справки.

Извлечение метаданных и списка видео с YouTube-каналов и плейлистов через `yt-dlp`.

- **Хост внутри сети:** `http://youtube:8000`
- **Проброшенный порт:** `8010`
- **Документация:** `/docs` (Swagger)

---

## Endpoints

### `GET /`

Редирект на `/docs` (Swagger UI).

---

### `GET /health`

Проверка здоровья сервиса.

**Response `200`:**

```json
{
  "status": "ok"
}
```

---

### `POST /api/v1/extract`

Принимает URL канала или плейлиста (YouTube и др.). Возвращает метаданные и список всех видео без скачивания.

**Request body:**

| Поле | Тип | Обязательный | Описание |
|---|---|---|---|
| `url` | string | да | URL канала, плейлиста или видео |

**Поддерживаемые форматы URL:**

- `https://www.youtube.com/playlist?list=XXX`
- `https://www.youtube.com/watch?v=YYY&list=XXX` (автоматически берёт плейлист)
- `https://www.youtube.com/@channel/videos`
- `https://www.youtube.com/@channel` (все видео канала)
- `https://youtu.be/XXX`
- `music.youtube.com/…`, `youtube-nocookie.com/…`
- Без URL — имя канала: `@BlackCabinet` или `BlackCabinet`

**Response `200`:**

```json
{
  "type": "playlist",
  "id": "PLxxx",
  "title": "Название плейлиста",
  "uploader": "Автор",
  "uploader_id": "@channel",
  "uploader_url": "https://www.youtube.com/@channel",
  "channel_id": "UCxxx",
  "webpage_url": "https://www.youtube.com/playlist?list=PLxxx",
  "description": "Описание...",
  "video_count": 42,
  "videos": [
    {
      "id": "dQw4w9WgXcQ",
      "url": "https://www.youtube.com/watch?v=dQw4w9WgXcQ",
      "title": "Название видео",
      "duration": 212,
      "view_count": 1234567,
      "upload_date": "20260101",
      "thumbnail": "https://i.ytimg.com/vi/...",
      "availability": "public",
      "channel": "Автор",
      "channel_id": "UCxxx"
    }
  ]
}
```

**Response `400`:** ошибка yt-dlp.

```json
{
  "detail": "yt-dlp error: ..."
}
```

**Response `404`:** URL не распознан.

```json
{
  "detail": "Could not extract info. Check the URL."
}
```

> ⚠️ Для больших каналов (1000+ видео) запрос может занимать 30–60 секунд.

---

## Docker

- **Image:** `./docker/youtube/Dockerfile` (python:3.11-slim)
- **Сервер:** uvicorn, порт `8000` внутри контейнера
- **Проброс портов:** `8010:8000`
- **Сеть:** `backend`
- **DNS:** 8.8.8.8, 1.1.1.1

## Зависимости

- `fastapi` — веб-фреймворк
- `uvicorn` — ASGI-сервер
- `yt-dlp` — извлечение метаданных YouTube
- `pydantic` — модели запросов/ответов