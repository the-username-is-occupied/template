"""SourcesAPI endpoints — wraps notebooklm-py SourcesAPI."""
from fastapi import APIRouter, Request

from models import SourceFulltextResponse, SourceListResponse, SourceResponse
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
async def get_source_fulltext(request: Request, account_id: str, notebook_id: str, source_id: str):
    """Get source full text."""
    client = get_client(account_id)
    fulltext = await client.sources.get_fulltext(notebook_id, source_id)
    return SourceFulltextResponse(
        response_time_ms=elapsed_ms(request),
        fulltext=pick(fulltext, "text", ""),
        format=pick(fulltext, "format", "markdown"),
    )


@router.post("/notebooks/{notebook_id}/sources/url")
async def add_source_url(request: Request, account_id: str, notebook_id: str, url: str):
    """Add a URL source."""
    client = get_client(account_id)
    source_data = await client.sources.add_url(notebook_id, url)
    return {"response_time_ms": elapsed_ms(request), "source": source_data}


@router.post("/notebooks/{notebook_id}/sources/text")
async def add_source_text(request: Request, account_id: str, notebook_id: str, text: str, title: str):
    """Add a plain-text source."""
    client = get_client(account_id)
    source_data = await client.sources.add_text(notebook_id, text, title)
    return {"response_time_ms": elapsed_ms(request), "source": source_data}


@router.delete("/notebooks/{notebook_id}/sources/{source_id}")
async def delete_source(request: Request, account_id: str, notebook_id: str, source_id: str):
    """Delete a source."""
    client = get_client(account_id)
    await client.sources.delete(notebook_id, source_id)
    return {"response_time_ms": elapsed_ms(request), "success": True}


@router.post("/notebooks/{notebook_id}/sources/{source_id}/wait_until_ready")
async def wait_until_ready(
    request: Request,
    account_id: str,
    notebook_id: str,
    source_id: str,
    timeout: int = 120,
):
    """Block until a source finishes processing (or *timeout* seconds pass)."""
    client = get_client(account_id)
    ready = await client.sources.wait_until_ready(notebook_id, source_id, timeout=timeout)
    return {"response_time_ms": elapsed_ms(request), "ready": ready}
