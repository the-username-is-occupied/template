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


def _is_youtube_domain(host: str) -> bool:
    """Check if the host is a known YouTube domain variant."""
    host = host.lower()
    return (
        "youtube.com" in host
        or host == "youtu.be"
        or host.endswith(".youtu.be")
    )


def _normalize_url(url: str) -> str:
    """
    Normalize YouTube URLs for consistent processing by yt-dlp.

    Handles all YouTube domain variants and converts them to
    canonical www.youtube.com URLs:
      - youtube.com, www.youtube.com, m.youtube.com, music.youtube.com
      - youtu.be, youtube-nocookie.com, youtubeeducation.com

    If the input is not a URL (no scheme/dot), it's treated as a
    channel name — e.g. "@BlackCabinet" or "BlackCabinet" becomes
    "https://www.youtube.com/@BlackCabinet".

    Transformations:
      - watch?v=XXX&list=YYY       →  playlist?list=YYY
      - youtu.be/XXX?list=YYY      →  playlist?list=YYY
      - youtu.be/XXX               →  https://www.youtube.com/watch?v=XXX
      - music.youtube.com/…        →  https://www.youtube.com/…
      - youtube-nocookie.com/…     →  https://www.youtube.com/…
    """
    # If it looks like a bare channel name (not a URL), prepend domain
    if not url.startswith("http"):
        maybe_channel = url.strip().lstrip("@")
        if "/" not in maybe_channel and not maybe_channel.startswith("www."):
            return f"https://www.youtube.com/@{maybe_channel}"

    parsed = urlparse(url)
    if not _is_youtube_domain(parsed.netloc):
        return url

    params = parse_qs(parsed.query)

    # If the URL has a list param, extract the canonical playlist URL
    if "list" in params:
        playlist_id = params["list"][0]
        return f"https://www.youtube.com/playlist?list={playlist_id}"

    # Normalise domain to www.youtube.com
    path = parsed.path.rstrip("/")
    host_lower = parsed.netloc.lower()

    # youtu.be/<video_id>  →  /watch?v=<video_id>
    if host_lower in ("youtu.be",) or host_lower.endswith(".youtu.be"):
        video_id = path.lstrip("/")
        if video_id and "/" not in video_id and not video_id.startswith("@"):
            return f"https://www.youtube.com/watch?v={video_id}"

    # Rebuild with www.youtube.com, preserving path and original query params
    result = f"https://www.youtube.com{path}"
    if parsed.query:
        result += f"?{parsed.query}"
    return result


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