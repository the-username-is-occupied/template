"""
Shared helpers for API route handlers.

Centralises three cross-cutting concerns that were previously duplicated
across every router file:

  1. elapsed_ms   — response time calculation
  2. pick         — unified dict / object attribute access
  3. map_*        — raw notebooklm-py data → Pydantic model
"""
from __future__ import annotations

import time
from typing import Any, List

from fastapi import Request

from models import Notebook, ShareStatus, SharedUser, Source


# ---------------------------------------------------------------------------
# Timing
# ---------------------------------------------------------------------------

def elapsed_ms(request: Request) -> int:
    """Milliseconds elapsed since the HTTP middleware recorded request start."""
    start = getattr(request.state, "start_time", time.time())
    return int((time.time() - start) * 1000)


# ---------------------------------------------------------------------------
# Generic attribute extraction
# ---------------------------------------------------------------------------

def pick(obj: Any, key: str, default: Any = None) -> Any:
    """Extract *key* from a dict or an object attribute, falling back to *default*."""
    if isinstance(obj, dict):
        return obj.get(key, default)
    return getattr(obj, key, default)


# ---------------------------------------------------------------------------
# Model mappers
# ---------------------------------------------------------------------------

def map_notebook(data: Any) -> Notebook:
    return Notebook(
        id=pick(data, "id"),
        title=pick(data, "title"),
        created_at=pick(data, "created_at"),
        sources_count=pick(data, "sources_count", 0),
        is_owner=pick(data, "is_owner", True),
    )


def map_source(data: Any) -> Source:
    return Source(
        id=pick(data, "id"),
        title=pick(data, "title"),
        url=pick(data, "url"),
        created_at=pick(data, "created_at"),
        status=pick(data, "status"),
        kind=pick(data, "kind"),
        is_ready=pick(data, "is_ready", False),
        is_processing=pick(data, "is_processing", False),
        is_error=pick(data, "is_error", False),
    )


def map_share_status(notebook_id: str, obj: Any) -> ShareStatus:
    raw_users: list = pick(obj, "shared_users") or []
    shared_users: List[SharedUser] = [
        SharedUser(
            email=pick(u, "email", ""),
            permission=pick(u, "permission", "VIEWER"),
            display_name=pick(u, "display_name"),
            avatar_url=pick(u, "avatar_url"),
        )
        for u in raw_users
    ]
    return ShareStatus(
        notebook_id=notebook_id,
        is_public=pick(obj, "is_public", False),
        access=pick(obj, "access", "RESTRICTED"),
        view_level=pick(obj, "view_level", "FULL_NOTEBOOK"),
        shared_users=shared_users,
        share_url=pick(obj, "share_url"),
    )
