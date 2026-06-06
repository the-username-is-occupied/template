"""
SourcesAPI endpoints - wraps notebooklm-py SourcesAPI.
"""
from typing import List, Optional
import time

from fastapi import APIRouter, Depends, HTTPException, Request
from main import get_client, app
from models import (
    Source,
    SourceListResponse,
    SourceResponse,
    SourceFulltextResponse,
)


router = APIRouter()


@router.get("/notebooks/{notebook_id}/sources", response_model=SourceListResponse)
async def list_sources(request: Request, account_id: str, notebook_id: str):
    """List all sources in a notebook."""
    client = get_client(account_id)
    
    
    sources_data = await client.sources.list(notebook_id)
    
    sources = []
    for s in sources_data:
        # Handle both dict and object returns from notebooklm-py
        if isinstance(s, dict):
            source = Source(
                id=s.get("id"),
                title=s.get("title"),
                url=s.get("url"),
                created_at=s.get("created_at"),
                status=s.get("status"),
                kind=s.get("kind"),
                is_ready=s.get("is_ready", False),
                is_processing=s.get("is_processing", False),
                is_error=s.get("is_error", False)
            )
        else:
            # Object with attributes
            source = Source(
                id=getattr(s, 'id', None),
                title=getattr(s, 'title', None),
                url=getattr(s, 'url', None),
                created_at=getattr(s, 'created_at', None),
                status=getattr(s, 'status', None),
                kind=getattr(s, 'kind', None),
                is_ready=getattr(s, 'is_ready', False),
                is_processing=getattr(s, 'is_processing', False),
                is_error=getattr(s, 'is_error', False)
            )
        sources.append(source)
        
    start_time = int((time.time() - request.state.start_time) * 1000)

    return SourceListResponse(
        response_time_ms=start_time,
        sources=sources
    )


@router.get("/notebooks/{notebook_id}/sources/{source_id}", response_model=SourceResponse)
async def get_source(request: Request, account_id: str, notebook_id: str, source_id: str):
    """Get source details."""
    client = get_client(account_id)
    
    source_data = await client.sources.get(notebook_id, source_id)
    
    # Handle both dict and object returns
    if isinstance(source_data, dict):
        source = Source(
            id=source_data.get("id"),
            title=source_data.get("title"),
            url=source_data.get("url"),
            created_at=source_data.get("created_at"),
            status=source_data.get("status"),
            kind=source_data.get("kind"),
            is_ready=source_data.get("is_ready", False),
            is_processing=source_data.get("is_processing", False),
            is_error=source_data.get("is_error", False)
        )
    else:
        source = Source(
            id=getattr(source_data, 'id', None),
            title=getattr(source_data, 'title', None),
            url=getattr(source_data, 'url', None),
            created_at=getattr(source_data, 'created_at', None),
            status=getattr(source_data, 'status', None),
            kind=getattr(source_data, 'kind', None),
            is_ready=getattr(source_data, 'is_ready', False),
            is_processing=getattr(source_data, 'is_processing', False),
            is_error=getattr(source_data, 'is_error', False)
        )
    
    start_time = int((time.time() - request.state.start_time) * 1000)
    
    return SourceResponse(
        response_time_ms=start_time,
        source=source
    )


@router.get("/notebooks/{notebook_id}/sources/{source_id}/fulltext", response_model=SourceFulltextResponse)
async def get_source_fulltext(request: Request, account_id: str, notebook_id: str, source_id: str):
    """Get source full text."""
    client = get_client(account_id)
    
    fulltext = await client.sources.get_fulltext(notebook_id, source_id)
    
    start_time = int((time.time() - request.state.start_time) * 1000)
    
    return SourceFulltextResponse(
        response_time_ms=start_time,
        fulltext=fulltext.get("text", ""),
        format=fulltext.get("format", "markdown")
    )


@router.post("/notebooks/{notebook_id}/sources/url")
async def add_source_url(request: Request, account_id: str, notebook_id: str, url: str):
    """Add a URL source."""
    client = get_client(account_id)
    
    source_data = await client.sources.add_url(notebook_id, url)
    
    start_time = int((time.time() - request.state.start_time) * 1000)
    
    return {
        "response_time_ms": start_time,
        "source": source_data
    }


@router.post("/notebooks/{notebook_id}/sources/text")
async def add_source_text(request: Request, account_id: str, notebook_id: str, text: str, title: str):
    """Add a text source."""
    client = get_client(account_id)
    
    source_data = await client.sources.add_text(notebook_id, text, title)
    
    start_time = int((time.time() - request.state.start_time) * 1000)
    
    return {
        "response_time_ms": start_time,
        "source": source_data
    }


@router.delete("/notebooks/{notebook_id}/sources/{source_id}")
async def delete_source(request: Request, account_id: str, notebook_id: str, source_id: str):
    """Delete a source."""
    client = get_client(account_id)
    
    await client.sources.delete(notebook_id, source_id)
    
    start_time = int((time.time() - request.state.start_time) * 1000)
    
    return {
        "response_time_ms": start_time,
        "success": True
    }


@router.post("/notebooks/{notebook_id}/sources/{source_id}/wait_until_ready")
async def wait_until_ready(request: Request, account_id: str, notebook_id: str, source_id: str, timeout: int = 120):
    """Wait for source to be ready."""
    client = get_client(account_id)
    
    result = await client.sources.wait_until_ready(notebook_id, source_id, timeout=timeout)
    
    start_time = int((time.time() - request.state.start_time) * 1000)
    
    return {
        "response_time_ms": start_time,
        "ready": result
    }
