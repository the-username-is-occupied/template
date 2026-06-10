import asyncio
from concurrent.futures import ThreadPoolExecutor
from urllib.parse import urlparse, parse_qs

from fastapi import APIRouter, HTTPException
import yt_dlp

from app.schemas import ExtractRequest, ExtractResponse, VideoEntry

router = APIRouter()
executor = ThreadPoolExecutor(max_workers=4)

YDL_OPTS = {
    "quiet": True,
    "no_warnings": False,
    "extract_flat": "in_playlist",
    "ignoreerrors": False,
    "socket_timeout": 30,
    "noplaylist": False,
}


def _normalize_url(url: str) -> str:
    """
    watch?v=XXX&list=YYY  →  playlist?list=YYY
    """
    parsed = urlparse(url)
    if "youtube.com" in parsed.netloc:
        params = parse_qs(parsed.query)
        if "list" in params:
            playlist_id = params["list"][0]
            return f"https://www.youtube.com/playlist?list={playlist_id}"
    return url


def _run_extract(url: str) -> dict:
    """
    Синхронная обёртка над yt-dlp.
    ВАЖНО: list(entries) должен быть внутри `with` блока,
    иначе ленивый генератор yt-dlp закрывается вместе с YDL-инстансом.
    """
    normalized = _normalize_url(url)
    with yt_dlp.YoutubeDL(YDL_OPTS) as ydl:
        info = ydl.extract_info(normalized, download=False)
        if info and info.get("entries") is not None:
            # Принудительно материализуем генератор пока YDL ещё открыт
            info["entries"] = list(info["entries"])
    return info


def _build_video_url(entry: dict) -> str:
    if entry.get("webpage_url"):
        return entry["webpage_url"]
    if entry.get("url") and entry["url"].startswith("http"):
        return entry["url"]
    return f"https://www.youtube.com/watch?v={entry.get('id', '')}"


def _parse_entries(entries: list) -> list[VideoEntry]:
    videos = []
    for entry in entries:
        if not entry:
            continue
        videos.append(VideoEntry(
            id=entry.get("id") or "",
            url=_build_video_url(entry),
            title=entry.get("title"),
            duration=entry.get("duration"),
            view_count=entry.get("view_count"),
            upload_date=entry.get("upload_date"),
            availability=entry.get("availability"),
            channel=entry.get("channel") or entry.get("uploader"),
            channel_id=entry.get("channel_id"),
        ))
    return videos


@router.post(
    "/extract",
    response_model=ExtractResponse,
    summary="Extract channel/playlist metadata and video URLs",
)
async def extract(request: ExtractRequest):
    """
    Принимает URL канала или плейлиста (YouTube и др.).
    Возвращает метаданные и список всех видео без скачивания.

    Поддерживаемые форматы URL:
    - https://www.youtube.com/playlist?list=XXX
    - https://www.youtube.com/watch?v=YYY&list=XXX  (автоматически берёт плейлист)
    - https://www.youtube.com/@channel/videos
    - https://www.youtube.com/@channel  (все видео канала)

    ⚠️ Для больших каналов (1000+ видео) запрос может занять 30–60 секунд.
    """
    loop = asyncio.get_event_loop()
    try:
        info = await loop.run_in_executor(executor, _run_extract, request.url)
    except Exception as e:
        raise HTTPException(status_code=400, detail=f"yt-dlp error: {e}")

    if not info:
        raise HTTPException(status_code=404, detail="Could not extract info. Check the URL.")

    entries = info.get("entries") or []
    videos = _parse_entries(entries)

    return ExtractResponse(
        type=info.get("_type", "playlist"),
        id=info.get("id"),
        title=info.get("title"),
        uploader=info.get("uploader"),
        uploader_id=info.get("uploader_id"),
        uploader_url=info.get("uploader_url"),
        channel_id=info.get("channel_id"),
        webpage_url=info.get("webpage_url"),
        description=info.get("description"),
        video_count=len(videos),
        videos=videos,
    )