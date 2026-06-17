"""
FastAPI-сервис для парсинга публичных Telegram-каналов.

Endpoints:
  GET  /status                        — состояние парсера
  POST /scrape                        — запустить парсинг
  GET  /channel/{channel}             — информация о канале
  GET  /post/{channel}/{post_id}      — один конкретный пост
"""

import asyncio
import logging
from datetime import datetime, date
from typing import Optional

from fastapi import FastAPI, HTTPException, BackgroundTasks
from pydantic import BaseModel, Field

from scraper import scrape_channel, get_channel_info, get_single_post

logging.basicConfig(
    level=logging.INFO,
    format="%(asctime)s [%(levelname)s] %(name)s: %(message)s",
)
logger = logging.getLogger(__name__)

app = FastAPI(
    title="TG Scraper API",
    description=(
        "Асинхронный скрапер публичных Telegram-каналов через t.me/s/.\n\n"
        "Результаты парсинга отправляются на указанный hook_url по мере накопления."
    ),
    version="1.0.0",
    docs_url="/docs",
    redoc_url="/redoc",
)


# ─── Глобальное состояние ────────────────────────────────────────────────────
# NOTE: состояние хранится в памяти, подходит для single-worker деплоя.

_state: dict = {
    "busy": False,
    "channel": None,
    "content_source_id": None,
    "started_at": None,
}


# ─── Pydantic-модели ─────────────────────────────────────────────────────────


class ScrapeRequest(BaseModel):
    content_source_id: str = Field(
        ..., description="ID источника контента — передаётся в каждый хук"
    )
    channel: str = Field(
        ..., description="Имя канала без @, например: habr_com"
    )
    limit: int = Field(
        0, ge=0, description="Максимальное количество постов. 0 = все посты"
    )
    from_id: Optional[int] = Field(
        None, description="Начать с этого post ID и идти назад"
    )
    to_id: Optional[int] = Field(
        None, description="Остановиться на этом post ID (включительно)"
    )
    from_date: Optional[date] = Field(
        None, description="Начать с этой даты и идти назад (формат: 2026-05-29)"
    )
    to_date: Optional[date] = Field(
        None, description="Остановиться на этой дате (формат: 2026-05-29)"
    )
    workers: int = Field(
        3, ge=1, le=5,
        description="Количество параллельных воркеров (1–5). Больше 5 — риск бана Cloudflare"
    )
    chunk_limit: int = Field(
        2000, ge=1,
        description="Количество постов в одном вызове хука (action=upload)"
    )
    hook_url: str = Field(
        ..., description="URL хука, куда отправляются результаты парсинга"
    )

    model_config = {
        "json_schema_extra": {
            "example": {
                "content_source_id": "source_42",
                "channel": "habr_com",
                "limit": 50,
                "from_date": "2026-05-01",
                "to_date": "2026-01-01",
                "workers": 3,
                "chunk_limit": 20,
                "hook_url": "http://app:80/api/webhooks/telegram-scraper",
            }
        }
    }


class ScrapeResponse(BaseModel):
    status: str
    channel: str
    content_source_id: str


class StatusResponse(BaseModel):
    busy: bool
    channel: Optional[str]
    content_source_id: Optional[str]
    started_at: Optional[str]


# ─── Endpoints ───────────────────────────────────────────────────────────────


@app.get(
    "/status",
    response_model=StatusResponse,
    summary="Статус парсера",
    tags=["Управление"],
)
async def status():
    """Возвращает текущий статус парсера: занят или свободен."""
    return _state


@app.post(
    "/scrape",
    response_model=ScrapeResponse,
    status_code=202,
    summary="Запустить парсинг канала",
    tags=["Парсинг"],
    responses={
        202: {"description": "Парсинг запущен"},
        409: {
            "description": "Парсер занят — уже выполняется другой парсинг",
            "content": {
                "application/json": {
                    "example": {
                        "detail": {
                            "error": "busy",
                            "message": "Scraping is already in progress",
                            "channel": "some_channel",
                            "started_at": "2026-06-01T10:00:00",
                        }
                    }
                }
            },
        },
    },
)
async def scrape(request: ScrapeRequest, background_tasks: BackgroundTasks):
    """
    Запускает асинхронный парсинг канала в фоновом режиме.

    Результаты отправляются на `hook_url`:
    - **action=upload** — очередная пачка постов (`chunk_limit` штук)
    - **action=done** — парсинг завершён, содержит `response_time` в мс

    Если парсинг уже запущен, возвращает **409 Conflict**.
    """
    # Log incoming scrape request parameters
    logger.info(
        "Received scrape request: %s",
        request.model_dump_json(indent=2, exclude_none=True),
    )

    if _state["busy"]:
        raise HTTPException(
            status_code=409,
            detail={
                "error": "busy",
                "message": "Scraping is already in progress",
                "channel": _state["channel"],
                "started_at": _state["started_at"],
            },
        )

    # Помечаем как занятый до старта фоновой задачи
    _state["busy"] = True
    _state["channel"] = request.channel
    _state["content_source_id"] = request.content_source_id
    _state["started_at"] = datetime.utcnow().isoformat()

    async def run() -> None:
        try:
            result = await scrape_channel(
                channel=request.channel,
                content_source_id=request.content_source_id,
                hook_url=request.hook_url,
                limit=request.limit,
                workers_count=request.workers,
                chunk_limit=request.chunk_limit,
                from_id=request.from_id,
                to_id=request.to_id,
                from_date=request.from_date,
                to_date=request.to_date,
            )
            logger.info(
                "Finished scraping @%s: %d posts in %dms",
                request.channel, result["total"], result["elapsed_ms"],
            )
        except Exception as e:
            logger.error(
                "Scraping failed for @%s: %s", request.channel, e, exc_info=True
            )
        finally:
            _state["busy"] = False
            _state["channel"] = None
            _state["content_source_id"] = None
            _state["started_at"] = None

    background_tasks.add_task(run)

    return ScrapeResponse(
        status="started",
        channel=request.channel,
        content_source_id=request.content_source_id,
    )


@app.get(
    "/channel/{channel}",
    summary="Информация о канале",
    tags=["Канал"],
    responses={
        200: {
            "description": "Мета-информация о канале",
            "content": {
                "application/json": {
                    "example": {
                        "channel": "habr_com",
                        "title": "Хабр",
                        "description": "Лучшие статьи Хабра",
                        "members": "250K",
                        "avatar_url": "https://cdn.telegram.org/...",
                        "content_source_id": "source_42",
                    }
                }
            },
        },
        404: {"description": "Канал не найден или недоступен"},
    },
)
async def channel_info(
    channel: str,
    content_source_id: Optional[str] = None,
):
    """
    Возвращает мета-информацию о публичном Telegram-канале:
    название, описание, количество подписчиков, аватар.

    `content_source_id` — опциональный идентификатор, будет включён в ответ если передан.
    """
    try:
        info = await get_channel_info(channel)
    except RuntimeError as e:
        raise HTTPException(status_code=404, detail=str(e))

    if content_source_id is not None:
        info["content_source_id"] = content_source_id

    return info


@app.get(
    "/post/{channel}/{post_id}",
    summary="Информация об одном посте",
    tags=["Посты"],
    responses={
        200: {"description": "Данные поста"},
        404: {"description": "Пост не найден"},
    },
)
async def single_post(channel: str, post_id: int):
    """
    Возвращает полные данные одного поста по имени канала и ID поста.

    Включает текст, форматирование, ссылки, реакции, опрос (если есть).
    """
    post = await get_single_post(channel, post_id)
    if post is None:
        raise HTTPException(
            status_code=404,
            detail=f"Post {post_id} not found in @{channel}",
        )
    return post
