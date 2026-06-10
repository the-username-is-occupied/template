"""
NotebookLM account pool management.
Handles client lifecycle: creation, keepalive, and graceful shutdown.
Kept separate from main.py so routers can import get_client without
creating a circular dependency.
"""
from __future__ import annotations

import random
from typing import TYPE_CHECKING, Any, Dict

from fastapi import HTTPException, status

if TYPE_CHECKING:
    from notebooklm import NotebookLMClient

# Two separate dicts: contexts own the lifecycle, clients expose the API.
_clients: Dict[str, "NotebookLMClient"] = {}
_contexts: Dict[str, Any] = {}


async def add_account(account_id: str, storage_path: str) -> None:
    """Add an account to the pool, initialising its Playwright session."""
    from notebooklm import NotebookLMClient  # lazy — may be absent in dev

    keepalive = random.randint(450, 600)
    # from_storage() is a sync call that returns a context manager;
    # __aenter__ starts the browser session and returns the live client.
    ctx = NotebookLMClient.from_storage(
        storage_path,
        keepalive=keepalive,
        rate_limit_max_retries=3,
        server_error_max_retries=3,
        chat_timeout=240
    )
    client = await ctx.__aenter__()
    _contexts[account_id] = ctx
    _clients[account_id] = client
    print(f"[INFO] Added account {account_id} (keepalive={keepalive}s)")


async def remove_account(account_id: str) -> None:
    """Gracefully close and remove an account from the pool."""
    ctx = _contexts.pop(account_id, None)
    _clients.pop(account_id, None)
    if ctx is not None:
        try:
            await ctx.__aexit__(None, None, None)
            print(f"[INFO] Closed client for account {account_id}")
        except Exception as exc:
            print(f"[ERROR] Failed to close client for {account_id}: {exc}")


def get_client(account_id: str) -> "NotebookLMClient":
    """Return the live client for *account_id* or raise HTTP 404."""
    if account_id not in _clients:
        raise HTTPException(
            status_code=status.HTTP_404_NOT_FOUND,
            detail={
                "error": "AccountNotFoundError",
                "message": f"Account {account_id} not found in pool",
                "account_id": account_id,
                "response_time_ms": 0,
            },
        )
    return _clients[account_id]


def active_account_ids() -> list[str]:
    """Snapshot of current pool keys (safe to iterate while mutating pool)."""
    return list(_clients.keys())


def pool_size() -> int:
    return len(_clients)
