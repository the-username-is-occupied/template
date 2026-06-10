"""
Async-скрапер публичных Telegram-каналов через t.me/s/
Используется как библиотека FastAPI-сервисом.
"""

import asyncio
import aiohttp
from bs4 import BeautifulSoup, NavigableString, Tag
import time
import re
import logging
from datetime import datetime, date
from typing import Optional, List, Dict, Any, Tuple

logger = logging.getLogger(__name__)

HEADERS = {
    "User-Agent": (
        "Mozilla/5.0 (Windows NT 10.0; Win64; x64) "
        "AppleWebKit/537.36 (KHTML, like Gecko) "
        "Chrome/124.0.0.0 Safari/537.36"
    ),
    "Accept-Language": "ru-RU,ru;q=0.9,en;q=0.8",
}

DELAY = 0.8
MAX_RETRIES = 3
ALL_POSTS_JUMP = 100_000  # Диапазон при limit=0 (все посты)


# ─── HTML-парсеры ────────────────────────────────────────────────────────────


def html_to_text(element) -> str:
    """Конвертирует HTML в plain text, сохраняя переносы и цитаты."""
    if element is None:
        return ""
    parts = []
    for child in element.children:
        if isinstance(child, NavigableString):
            parts.append(str(child))
        elif isinstance(child, Tag):
            if child.name == "br":
                parts.append("\n")
            elif child.name == "blockquote":
                inner = html_to_text(child)
                parts.append("\n".join(f"> {line}" for line in inner.splitlines()))
            else:
                parts.append(html_to_text(child))
    return "".join(parts)


def extract_links(element) -> List[str]:
    """Извлекает все внешние ссылки из элемента."""
    if element is None:
        return []
    return [
        a["href"]
        for a in element.find_all("a", href=True)
        if a["href"].startswith("http")
    ]


def parse_poll(msg) -> Optional[Dict]:
    """Парсит опрос из сообщения."""
    poll_el = msg.find("div", class_="tgme_widget_message_poll")
    if not poll_el:
        return None

    q_el = poll_el.find("div", class_="tgme_widget_message_poll_question")
    t_el = poll_el.find("div", class_="tgme_widget_message_poll_type")

    options = []
    for opt in poll_el.find_all("div", class_="tgme_widget_message_poll_option"):
        pct_el = opt.find("div", class_="tgme_widget_message_poll_option_percent")
        txt_el = opt.find("div", class_="tgme_widget_message_poll_option_text")
        bar_el = opt.find("div", class_="tgme_widget_message_poll_option_bar")

        bar_width = None
        if bar_el:
            m = re.search(r"width:([\d.]+)%", bar_el.get("style", ""))
            if m:
                bar_width = float(m.group(1))

        options.append({
            "text": txt_el.get_text(strip=True) if txt_el else "",
            "percent": pct_el.get_text(strip=True) if pct_el else None,
            "bar_width": bar_width,
        })

    return {
        "question": q_el.get_text(strip=True) if q_el else "",
        "type": t_el.get_text(strip=True) if t_el else "",
        "options": options,
    }


def parse_reactions(msg) -> List[Dict]:
    """Парсит реакции под постом."""
    el = msg.find("div", class_="tgme_widget_message_reactions")
    if not el:
        return []
    result = []
    for span in el.find_all("span", class_="tgme_reaction"):
        b = span.find("b")
        emoji = b.get_text(strip=True) if b else ""
        count = span.get_text(strip=True).replace(emoji, "").strip()
        if emoji:
            result.append({"emoji": emoji, "count": count})
    return result


def parse_posts(html: str, channel: str) -> List[Dict]:
    """Парсит список постов из HTML-страницы t.me/s/channel."""
    soup = BeautifulSoup(html, "html.parser")
    posts = []

    for wrap in soup.find_all("div", class_="tgme_widget_message_wrap"):
        msg = wrap.find("div", class_="tgme_widget_message")
        if not msg:
            continue

        data_post = msg.get("data-post", "")
        post_id_str = data_post.split("/")[-1] if "/" in data_post else None
        if not post_id_str or not post_id_str.isdigit():
            continue
        post_id = int(post_id_str)

        # Текст: plain + HTML с сохранением форматирования
        text_el = msg.find("div", class_="tgme_widget_message_text")
        text = html_to_text(text_el) if text_el else ""
        text_html = text_el.decode_contents() if text_el else ""
        links = extract_links(text_el)

        # Дата
        date_iso = None
        for container in (msg, wrap):
            date_link = container.find("a", class_="tgme_widget_message_date")
            if date_link:
                time_el = date_link.find("time")
                if time_el:
                    date_iso = time_el.get("datetime")
                    break

        # Просмотры
        views_el = msg.find("span", class_="tgme_widget_message_views")
        views = views_el.get_text(strip=True) if views_el else None

        # Тип контента
        has_photo = bool(msg.find("a", class_="tgme_widget_message_photo_wrap"))
        has_video = bool(msg.find("div", class_="tgme_widget_message_video_wrap"))
        has_doc = bool(msg.find("div", class_="tgme_widget_message_document"))
        has_poll = bool(msg.find("div", class_="tgme_widget_message_poll"))

        if has_photo:
            content_type = "photo"
        elif has_video:
            content_type = "video"
        elif has_doc:
            content_type = "document"
        elif has_poll:
            content_type = "poll"
        else:
            content_type = "text"

        # Репост: имя + ссылка
        fwd_el = msg.find("a", class_="tgme_widget_message_forwarded_from_name")
        forwarded_from = None
        if fwd_el:
            forwarded_from = {
                "name": fwd_el.get_text(strip=True),
                "url": fwd_el.get("href"),
            }

        posts.append({
            "id": post_id,
            "url": f"https://t.me/{channel}/{post_id}",
            "date": date_iso,
            "text": text,
            "text_html": text_html,
            "links": links,
            "views": views,
            "type": content_type,
            "forwarded_from": forwarded_from,
            "poll": parse_poll(msg) if has_poll else None,
            "reactions": parse_reactions(msg),
        })

    return posts


def parse_channel_info(html: str, channel: str) -> Dict:
    """Парсит информацию о канале."""
    soup = BeautifulSoup(html, "html.parser")

    title_el = (
        soup.find("div", class_="tgme_channel_info_header_title")
        or soup.find("div", class_="tgme_page_title")
    )
    desc_el = (
        soup.find("div", class_="tgme_channel_info_description")
        or soup.find("div", class_="tgme_page_description")
    )

    members = None
    for ctr in soup.find_all("div", class_="tgme_channel_info_counter"):
        val_el = ctr.find("span", class_="counter_value")
        typ_el = ctr.find("span", class_="counter_type")
        if val_el and typ_el:
            t = typ_el.get_text(strip=True).lower()
            if any(w in t for w in ("member", "subscriber", "участник", "подписчик")):
                members = val_el.get_text(strip=True)

    avatar_el = soup.find("img", class_="tgme_page_photo_image")

    return {
        "channel": channel,
        "title": title_el.get_text(strip=True) if title_el else None,
        "description": desc_el.get_text(strip=True) if desc_el else None,
        "members": members,
        "avatar_url": avatar_el.get("src") if avatar_el else None,
    }


# ─── Сеть ────────────────────────────────────────────────────────────────────


async def fetch_page(
    session: aiohttp.ClientSession, channel: str, before_id: Optional[int] = None
) -> Optional[str]:
    """Загружает страницу канала. before_id — пагинация назад."""
    url = f"https://t.me/s/{channel}"
    if before_id:
        url += f"?before={before_id}"

    for attempt in range(MAX_RETRIES):
        try:
            async with session.get(
                url, headers=HEADERS, timeout=aiohttp.ClientTimeout(total=20)
            ) as resp:
                if resp.status == 429:
                    wait = 5 * (attempt + 1)
                    logger.warning("Rate limited, waiting %ds", wait)
                    await asyncio.sleep(wait)
                    continue
                if resp.status != 200:
                    logger.warning("HTTP %d for %s", resp.status, url)
                    return None
                return await resp.text()
        except (aiohttp.ClientError, asyncio.TimeoutError) as e:
            logger.warning("Request error (attempt %d): %s", attempt + 1, e)
            if attempt < MAX_RETRIES - 1:
                await asyncio.sleep(2 ** attempt)

    return None


async def send_webhook(
    session: aiohttp.ClientSession,
    hook_url: str,
    content_source_id: str,
    action: str,
    posts: Optional[List] = None,
    response_time: Optional[int] = None,
) -> bool:
    """Отправляет хук с постами (action=upload) или уведомление о завершении (action=done)."""
    payload: Dict[str, Any] = {
        "content_source_id": content_source_id,
        "action": action,
    }
    if action == "upload" and posts is not None:
        payload["posts"] = posts
    if action == "done" and response_time is not None:
        payload["response_time"] = response_time

    for attempt in range(MAX_RETRIES):
        try:
            async with session.post(
                hook_url, json=payload, timeout=aiohttp.ClientTimeout(total=60)
            ) as resp:
                if resp.status < 300:
                    return True
                logger.warning("Webhook returned %d for action=%s", resp.status, action)
                return False
        except Exception as e:
            logger.warning("Webhook error (attempt %d): %s", attempt + 1, e)
            await asyncio.sleep(2 ** attempt)

    return False


# ─── Определение диапазона ID ────────────────────────────────────────────────


async def discover_range(
    session: aiohttp.ClientSession, channel: str, limit: int
) -> Tuple[int, int]:
    """Определяет (max_id, estimated_min_id) для диапазона парсинга."""
    html = await fetch_page(session, channel)
    if not html:
        raise RuntimeError(f"Не удалось открыть t.me/s/{channel}")

    posts = parse_posts(html, channel)
    if not posts:
        raise RuntimeError("Канал пуст или недоступен")

    max_id = max(p["id"] for p in posts)
    effective = limit if limit > 0 else ALL_POSTS_JUMP
    jump = max(500, int(effective * 1.3))

    html2 = await fetch_page(session, channel, max(1, max_id - jump))
    if html2:
        pp = parse_posts(html2, channel)
        if pp:
            return max_id, min(p["id"] for p in pp)

    return max_id, max(1, max_id - jump)


# ─── Воркер ──────────────────────────────────────────────────────────────────


def _post_date(post: Dict) -> Optional[date]:
    dt_str = post.get("date")
    if not dt_str:
        return None
    try:
        return datetime.fromisoformat(dt_str).date()
    except Exception:
        return None


async def run_worker(
    worker_id: int,
    session: aiohttp.ClientSession,
    channel: str,
    start_before: int,
    stop_id: int,
    from_date: Optional[date],
    to_date: Optional[date],
    seen_ids: set,
    all_results: list,
    pending: list,
    lock: asyncio.Lock,
    stop_event: asyncio.Event,
    limit: int,
) -> None:
    """Скрапит диапазон ID (stop_id, start_before]."""
    current = start_before

    while not stop_event.is_set():
        html = await fetch_page(session, channel, current)
        if not html:
            break

        page_posts = parse_posts(html, channel)
        if not page_posts:
            break

        to_add = []
        should_stop = False

        for p in page_posts:
            if p["id"] <= stop_id:
                continue

            pd = _post_date(p)
            if from_date and pd and pd > from_date:
                continue  # Пост новее from_date — пропускаем
            if to_date and pd and pd < to_date:
                should_stop = True  # Пост старее to_date — останавливаемся
                break

            to_add.append(p)

        if to_add:
            async with lock:
                for p in to_add:
                    if p["id"] not in seen_ids:
                        seen_ids.add(p["id"])
                        all_results.append(p)
                        pending.append(p)
                        if limit > 0 and len(all_results) >= limit:
                            stop_event.set()
                            break

        min_id = min(p["id"] for p in page_posts)
        if min_id <= stop_id or should_stop or stop_event.is_set():
            break

        current = min_id
        await asyncio.sleep(DELAY)


# ─── Главная функция парсинга ────────────────────────────────────────────────


async def scrape_channel(
    channel: str,
    content_source_id: str,
    hook_url: str,
    limit: int = 0,
    workers_count: int = 3,
    chunk_limit: int = 2000,
    from_id: Optional[int] = None,
    to_id: Optional[int] = None,
    from_date: Optional[date] = None,
    to_date: Optional[date] = None,
) -> Dict:
    """
    Параллельно скрапит канал и отправляет результаты на hook_url.
    
    По мере накопления chunk_limit постов вызывает хук с action=upload.
    По завершении вызывает хук с action=done и response_time в мс.
    """
    t0 = time.monotonic()

    seen_ids: set = set()
    all_results: list = []
    pending: list = []
    lock = asyncio.Lock()
    stop_event = asyncio.Event()
    total_sent = [0]

    connector = aiohttp.TCPConnector(limit=workers_count + 4)
    async with aiohttp.ClientSession(connector=connector) as session:

        # Определяем диапазон ID
        if from_id:
            max_id = from_id
            eff = limit if limit > 0 else ALL_POSTS_JUMP
            jump = max(500, int(eff * 1.3))
            est_min = to_id if to_id else max(1, max_id - jump)
        else:
            max_id, est_min = await discover_range(session, channel, limit)
            if to_id:
                est_min = to_id

        logger.info("Scraping @%s: ID range %d – %d", channel, est_min, max_id)

        # Разбиваем диапазон на чанки для воркеров
        total_range = max(1, max_id - est_min)
        chunk_size = max(1, total_range // workers_count)
        ranges = [
            (max_id - i * chunk_size, max_id - (i + 1) * chunk_size)
            for i in range(workers_count)
        ]
        ranges[-1] = (ranges[-1][0], est_min - 1)

        async def flush(force: bool = False) -> None:
            """Сбрасывает накопленные посты на хук чанками по chunk_limit."""
            batches = []
            async with lock:
                threshold = 1 if force else chunk_limit
                while len(pending) >= threshold:
                    batch = pending[:chunk_limit]
                    del pending[:chunk_limit]
                    batches.append(batch)
            for batch in batches:
                ok = await send_webhook(
                    session, hook_url, content_source_id, "upload", posts=batch
                )
                if ok:
                    total_sent[0] += len(batch)
                    logger.info("Webhook upload sent: %d posts (total %d)", len(batch), total_sent[0])

        async def periodic_flush() -> None:
            while True:
                await asyncio.sleep(3)
                await flush()

        # Запускаем воркеры
        worker_tasks = [
            asyncio.create_task(
                run_worker(
                    i, session, channel, s, e,
                    from_date, to_date,
                    seen_ids, all_results, pending,
                    lock, stop_event, limit,
                )
            )
            for i, (s, e) in enumerate(ranges)
        ]

        flush_task = asyncio.create_task(periodic_flush())
        await asyncio.gather(*worker_tasks)

        # Останавливаем периодический флаш и сбрасываем остаток
        flush_task.cancel()
        try:
            await flush_task
        except asyncio.CancelledError:
            pass

        await flush(force=True)

        elapsed_ms = int((time.monotonic() - t0) * 1000)
        await send_webhook(
            session, hook_url, content_source_id, "done", response_time=elapsed_ms
        )
        logger.info(
            "Scraping done: channel=%s total=%d elapsed=%dms",
            channel, total_sent[0], elapsed_ms,
        )

    return {"total": total_sent[0], "elapsed_ms": elapsed_ms}


# ─── Публичные хелперы ───────────────────────────────────────────────────────


async def get_channel_info(channel: str) -> Dict:
    """Возвращает мета-информацию о канале."""
    connector = aiohttp.TCPConnector(limit=3)
    async with aiohttp.ClientSession(connector=connector) as session:
        html = await fetch_page(session, channel)
        if not html:
            raise RuntimeError(f"Не удалось загрузить t.me/s/{channel}")
        return parse_channel_info(html, channel)


async def get_single_post(channel: str, post_id: int) -> Optional[Dict]:
    """Возвращает один пост по каналу и ID."""
    connector = aiohttp.TCPConnector(limit=3)
    async with aiohttp.ClientSession(connector=connector) as session:
        # Загружаем страницу с before_id = post_id + 1, чтобы нужный пост попал в выдачу
        html = await fetch_page(session, channel, post_id + 1)
        if not html:
            return None
        posts = parse_posts(html, channel)
        for p in posts:
            if p["id"] == post_id:
                return p
        return None
