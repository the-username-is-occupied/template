# API Endpoints — FastAPI сервисы

Документация эндпоинтов вспомогательных FastAPI-сервисов.

| Сервис | Описание | Внутренний хост | Внешний порт |
|---|---|---|---|
| [TG Scraper](tg-scraper.md) | Парсинг публичных Telegram-каналов через `t.me/s/` | `http://telegramm:8000` | `8020` |
| [YT Scraper](yt-scraper.md) | Извлечение видео и метаданных с YouTube через `yt-dlp` | `http://youtube:8000` | `8010` |

## Стек

Оба сервиса написаны на **FastAPI** (Python 3.11), запускаются через **uvicorn**.
Документация в формате OpenAPI доступна по `/docs` каждого сервиса.

## Сеть

Сервисы находятся в Docker-сети `backend` и доступны другим контейнерам
по именам `telegramm` и `youtube` соответственно.

## Конфигурация в Laravel

- TG Scraper: `config/tg-scraper.php` — `TG_SCRAPER_BASE_URL`
- YT Scraper: конфиг отсутствует (вызовы через прямой HTTP)