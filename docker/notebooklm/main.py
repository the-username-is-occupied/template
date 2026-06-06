"""
FastAPI service for NotebookLM integration.
Wraps notebooklm-py client with account pool management.
"""
from __future__ import annotations

import os
import time
from contextlib import asynccontextmanager
from pathlib import Path

from fastapi import FastAPI, HTTPException, Request, status
from fastapi.middleware.cors import CORSMiddleware
from fastapi.responses import JSONResponse

# notebooklm-py may be absent in dev/CI environments — stub out error classes
# so the exception handlers below remain valid Python regardless.
try:
    from notebooklm import (
        AuthError,
        NetworkError,
        NotebookLimitError,
        NotebookLMError,
        RateLimitError,
    )
except ImportError as _exc:
    print(f"[WARNING] notebooklm-py not available: {_exc}")

    class NotebookLMError(Exception): ...       # type: ignore[no-redef]
    class RateLimitError(NotebookLMError): ...  # type: ignore[no-redef]
    class AuthError(NotebookLMError): ...       # type: ignore[no-redef]
    class NetworkError(NotebookLMError): ...    # type: ignore[no-redef]
    class NotebookLimitError(NotebookLMError): ...  # type: ignore[no-redef]

import pool
from pool import add_account, active_account_ids, pool_size, remove_account

# Routers no longer import from main.py (they use pool.py), so this is safe
# to place at the top — no circular dependency.
from api.chat import router as chat_router
from api.notebooks import router as notebooks_router
from api.settings import router as settings_router
from api.sharing import router as sharing_router
from api.sources import router as sources_router


# ---------------------------------------------------------------------------
# Startup / shutdown
# ---------------------------------------------------------------------------

async def _init_accounts() -> None:
    cookies_path = Path(os.getenv("COOKIES_PATH", "/cookies"))
    if not cookies_path.exists():
        print(f"[WARN] Cookies path {cookies_path} does not exist")
        return
    for account_dir in sorted(cookies_path.iterdir()):
        if not account_dir.is_dir():
            continue
        storage_file = account_dir / "storage_state.json"
        if not storage_file.exists():
            continue
        try:
            await add_account(account_dir.name, str(storage_file))
        except Exception as exc:
            print(f"[ERROR] Failed to initialise account {account_dir.name}: {exc}")


@asynccontextmanager
async def lifespan(app: FastAPI):
    await _init_accounts()
    yield
    # Iterate over a snapshot so remove_account can safely mutate the pool.
    for account_id in active_account_ids():
        await remove_account(account_id)


# ---------------------------------------------------------------------------
# App
# ---------------------------------------------------------------------------

app = FastAPI(
    title="NotebookLM FastAPI Service",
    description="FastAPI wrapper for notebooklm-py with account pool management",
    version="1.0.0",
    lifespan=lifespan,
)

app.add_middleware(
    CORSMiddleware,
    allow_origins=["*"],  # internal network only
    allow_credentials=True,
    allow_methods=["*"],
    allow_headers=["*"],
)

_PREFIX = "/accounts/{account_id}"
app.include_router(notebooks_router, prefix=_PREFIX, tags=["notebooks"])
app.include_router(sources_router,   prefix=_PREFIX, tags=["sources"])
app.include_router(chat_router,      prefix=_PREFIX, tags=["chat"])
app.include_router(settings_router,  prefix=_PREFIX, tags=["settings"])
app.include_router(sharing_router,   prefix=_PREFIX, tags=["sharing"])


# ---------------------------------------------------------------------------
# Middleware
# ---------------------------------------------------------------------------

@app.middleware("http")
async def record_response_time(request: Request, call_next):
    """Stamp every request with its start time and echo elapsed ms in headers."""
    request.state.start_time = time.time()
    response = await call_next(request)
    elapsed_ms = int((time.time() - request.state.start_time) * 1000)
    response.headers["X-Response-Time-Ms"] = str(elapsed_ms)
    return response


# ---------------------------------------------------------------------------
# Exception handlers
# ---------------------------------------------------------------------------

def _elapsed_ms(request: Request) -> int:
    start = getattr(request.state, "start_time", time.time())
    return int((time.time() - start) * 1000)


@app.exception_handler(NotebookLMError)
async def notebooklm_error_handler(request: Request, exc: NotebookLMError):
    ms = _elapsed_ms(request)

    if isinstance(exc, RateLimitError):
        return JSONResponse(
            status_code=status.HTTP_429_TOO_MANY_REQUESTS,
            content={
                "error": "RateLimitError",
                "message": str(exc),
                "retry_after": getattr(exc, "retry_after", 60),
                "response_time_ms": ms,
            },
        )
    if isinstance(exc, AuthError):
        return JSONResponse(
            status_code=status.HTTP_503_SERVICE_UNAVAILABLE,
            content={"error": "AuthError", "message": "Account session expired", "response_time_ms": ms},
        )
    if isinstance(exc, NetworkError):
        return JSONResponse(
            status_code=status.HTTP_503_SERVICE_UNAVAILABLE,
            content={"error": "NetworkError", "message": "NotebookLM unreachable", "response_time_ms": ms},
        )
    if isinstance(exc, NotebookLimitError):
        return JSONResponse(
            status_code=status.HTTP_507_INSUFFICIENT_STORAGE,
            content={"error": "NotebookLimitError", "message": str(exc), "response_time_ms": ms},
        )
    return JSONResponse(
        status_code=status.HTTP_500_INTERNAL_SERVER_ERROR,
        content={"error": "NotebookLMError", "message": str(exc), "response_time_ms": ms},
    )


# ---------------------------------------------------------------------------
# Built-in routes
# ---------------------------------------------------------------------------

@app.get("/health")
async def health_check():
    return {"status": "ok", "clients_count": pool_size()}


@app.get("/health/accounts")
async def health_accounts():
    cookies_path = Path(os.getenv("COOKIES_PATH", "/cookies"))
    result = {}
    for account_id in active_account_ids():
        storage_file = cookies_path / account_id / "storage_state.json"
        info: dict = {"is_connected": True, "status": "healthy"}
        if storage_file.exists():
            age = int(time.time() - storage_file.stat().st_mtime)
            info["mtime_age_seconds"] = age
            info["mtime"] = "healthy" if age < 600 else "stale"
        else:
            info["mtime"] = "missing"
            info["status"] = "degraded"
        result[account_id] = info
    return result


@app.post("/accounts/{account_id}")
async def create_account(account_id: str):
    cookies_path = Path(os.getenv("COOKIES_PATH", "/cookies"))
    storage_path = cookies_path / account_id / "storage_state.json"
    if not storage_path.exists():
        raise HTTPException(
            status_code=status.HTTP_400_BAD_REQUEST,
            detail={
                "error": "AccountInitializationError",
                "message": f"storage_state.json not found for account {account_id}",
                "account_id": account_id,
                "response_time_ms": 0,
            },
        )
    try:
        await add_account(account_id, str(storage_path))
        return {"status": "created", "account_id": account_id}
    except Exception as exc:
        raise HTTPException(
            status_code=status.HTTP_500_INTERNAL_SERVER_ERROR,
            detail={
                "error": "AccountInitializationError",
                "message": f"Failed to initialise account: {exc}",
                "account_id": account_id,
                "response_time_ms": 0,
            },
        )


@app.delete("/accounts/{account_id}")
async def delete_account(account_id: str):
    await remove_account(account_id)
    return {"status": "deleted", "account_id": account_id}


if __name__ == "__main__":
    import uvicorn

    uvicorn.run(
        "main:app",
        host="0.0.0.0",
        port=int(os.getenv("FASTAPI_PORT", "8000")),
        # workers=1 is mandatory: NotebookLMClient is bound to the event loop;
        # forking would break all pooled clients.
        workers=1,
        log_level="info",
    )
