"""SourcesAPI endpoints — wraps notebooklm-py SourcesAPI."""
from fastapi import APIRouter, Request
from pydantic import Field
from typing import List, Optional

from models import (
    SourceFulltextResponse,
    SourceListResponse,
    SourceResponse,
    SourceGuideResponse,
    SourceRenameResponse,
    SourceAddUrlRequest,
    SourceAddUrlResponse,
    SourceAddTextRequest,
    SourceAddTextResponse,
    SourceAddFileRequest,
    SourceAddFileResponse,
    SourceWaitRegisteredResponse,
    SourcesWaitMultipleResponse,
    SourceRefreshResponse,
    SourceFreshnessResponse,
)
from pool import get_client
from api.utils import elapsed_ms, map_source, pick

router = APIRouter()


@router.get("/notebooks/{notebook_id}/sources", response_model=SourceListResponse)
async def list_sources(request: Request, account_id: str, notebook_id: str):
    """List all sources in a notebook."""
    client = get_client(account_id)
    sources = [map_source(s) for s in await client.sources.list(notebook_id)]
    return SourceListResponse(response_time_ms=elapsed_ms(request), sources=sources)


@router.get("/notebooks/{notebook_id}/sources/{source_id}", response_model=SourceResponse)
async def get_source(request: Request, account_id: str, notebook_id: str, source_id: str):
    """Get source details."""
    client = get_client(account_id)
    data = await client.sources.get(notebook_id, source_id)
    return SourceResponse(response_time_ms=elapsed_ms(request), source=map_source(data))


@router.get(
    "/notebooks/{notebook_id}/sources/{source_id}/fulltext",
    response_model=SourceFulltextResponse,
)
async def get_source_fulltext(request: Request, account_id: str, notebook_id: str, source_id: str, output_format: str = "markdown"):
    """Get source full text."""
    client = get_client(account_id)
    fulltext = await client.sources.get_fulltext(notebook_id, source_id)
    return SourceFulltextResponse(
        source_id=pick(fulltext, "source_id", ""),
        title=pick(fulltext, "title", ""),
        content=pick(fulltext, "content", ""),
        url=pick(fulltext, "url", None),
        char_count=pick(fulltext, "char_count", 0),
        format=output_format,
    )


@router.get(
    "/notebooks/{notebook_id}/sources/{source_id}/guide",
    response_model=SourceGuideResponse,
)
async def get_source_guide(request: Request, account_id: str, notebook_id: str, source_id: str):
    """Get AI-generated summary and keywords for a source."""
    client = get_client(account_id)
    guide = await client.sources.get_guide(notebook_id, source_id)
    return SourceGuideResponse(
        summary=guide.get("summary", ""),
        keywords=guide.get("keywords", ""),
    )


@router.post("/notebooks/{notebook_id}/sources/url", response_model=SourceAddUrlResponse)
async def add_source_url(request: Request, account_id: str, notebook_id: str, body: SourceAddUrlRequest):
    """Add a URL source."""
    client = get_client(account_id)
    source_data = await client.sources.add_url(notebook_id, body.url)
    return SourceAddUrlResponse(
        response_time_ms=elapsed_ms(request),
        source=map_source(source_data),
    )


@router.post("/notebooks/{notebook_id}/sources/text", response_model=SourceAddTextResponse)
async def add_source_text(request: Request, account_id: str, notebook_id: str, body: SourceAddTextRequest):
    """Add a plain-text source."""
    client = get_client(account_id)
    source_data = await client.sources.add_text(notebook_id, body.text, body.title)
    return SourceAddTextResponse(
        response_time_ms=elapsed_ms(request),
        source=map_source(source_data),
    )


@router.post("/notebooks/{notebook_id}/sources/file", response_model=SourceAddFileResponse)
async def add_source_file(request: Request, account_id: str, notebook_id: str, body: SourceAddFileRequest):
    """Upload a file source."""
    client = get_client(account_id)
    source_data = await client.sources.add_file(
        notebook_id,
        body.file_path,
        title=body.title,
        wait=body.wait,
        wait_timeout=body.wait_timeout,
    )
    return SourceAddFileResponse(
        source=map_source(source_data),
    )


@router.post(
    "/notebooks/{notebook_id}/sources/{source_id}/rename",
    response_model=SourceRenameResponse,
)
async def rename_source(
    request: Request,
    account_id: str,
    notebook_id: str,
    source_id: str,
    new_title: str,
):
    """Rename a source."""
    client = get_client(account_id)
    source_data = await client.sources.rename(notebook_id, source_id, new_title)
    return SourceRenameResponse(
        source=map_source(source_data),
    )


@router.delete("/notebooks/{notebook_id}/sources/{source_id}")
async def delete_source(request: Request, account_id: str, notebook_id: str, source_id: str):
    """Delete a source."""
    client = get_client(account_id)
    await client.sources.delete(notebook_id, source_id)
    return {"response_time_ms": elapsed_ms(request), "success": True}


@router.post(
    "/notebooks/{notebook_id}/sources/{source_id}/refresh",
    response_model=SourceRefreshResponse,
)
async def refresh_source(request: Request, account_id: str, notebook_id: str, source_id: str):
    """Refresh a URL/Drive source."""
    client = get_client(account_id)
    result = await client.sources.refresh(notebook_id, source_id)
    return SourceRefreshResponse(success=result)


@router.get(
    "/notebooks/{notebook_id}/sources/{source_id}/freshness",
    response_model=SourceFreshnessResponse,
)
async def check_source_freshness(request: Request, account_id: str, notebook_id: str, source_id: str):
    """Check if a source needs refreshing."""
    client = get_client(account_id)
    is_fresh = await client.sources.check_freshness(notebook_id, source_id)
    return SourceFreshnessResponse(is_fresh=is_fresh)


@router.post(
    "/notebooks/{notebook_id}/sources/{source_id}/wait_until_ready",
    response_model=SourceWaitRegisteredResponse,
)
async def wait_until_ready(
    request: Request,
    account_id: str,
    notebook_id: str,
    source_id: str,
    timeout: int = 120,
):
    """Block until a source finishes processing (or *timeout* seconds pass)."""
    client = get_client(account_id)
    source_data = await client.sources.wait_until_ready(notebook_id, source_id, timeout=timeout)
    return SourceWaitRegisteredResponse(
        response_time_ms=elapsed_ms(request),
        source=map_source(source_data),
    )


@router.post(
    "/notebooks/{notebook_id}/sources/{source_id}/wait_until_registered",
    response_model=SourceWaitRegisteredResponse,
)
async def wait_until_registered(
    request: Request,
    account_id: str,
    notebook_id: str,
    source_id: str,
    timeout: float = 30.0,
):
    """Wait until a source is visible server-side (registered)."""
    client = get_client(account_id)
    source_data = await client.sources.wait_until_registered(
        notebook_id, source_id, timeout=timeout
    )
    return SourceWaitRegisteredResponse(
        source=map_source(source_data),
    )


@router.post(
    "/notebooks/{notebook_id}/sources/wait_for_sources",
    response_model=SourcesWaitMultipleResponse,
)
async def wait_for_sources(
    request: Request,
    account_id: str,
    notebook_id: str,
    source_ids: List[str],
    timeout: float = 120.0,
):
    """Wait for multiple sources to become ready in parallel."""
    client = get_client(account_id)
    sources = await client.sources.wait_for_sources(notebook_id, source_ids, timeout=timeout)
    return SourcesWaitMultipleResponse(
        sources=[map_source(s) for s in sources],
    )
