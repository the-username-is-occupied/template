"""
FastAPI service for NotebookLM integration.
Wraps notebooklm-py client with account pool management.
"""
import os
import json
import time
import random
from pathlib import Path
from typing import Dict, Optional, Any
from contextlib import asynccontextmanager

from fastapi import FastAPI, HTTPException, Request, status
from fastapi.responses import JSONResponse
from fastapi.middleware.cors import CORSMiddleware

# Import notebooklm-py
NOTEBOOKLM_AVAILABLE = False
try:
    from notebooklm import NotebookLMClient
    from notebooklm import (
        NotebookLMError,
        RateLimitError,
        AuthError,
        NetworkError,
        NotebookLimitError,
    )
    NOTEBOOKLM_AVAILABLE = True
except ImportError as e:
    print(f"[WARNING] notebooklm-py not available: {e}")
    NotebookLMClient = None
    NotebookLMError = Exception
    RateLimitError = Exception
    AuthError = Exception
    NetworkError = Exception
    NotebookLimitError = Exception

from models import *

# ИСПРАВЛЕНИЕ: храним контексты (_FromStorageContext) и клиентов раздельно.
# _contexts нужны для корректного __aexit__ при завершении,
# _clients используются в роутерах для API-вызовов.
_clients: Dict[str, "NotebookLMClient"] = {}
_contexts: Dict[str, Any] = {}


@asynccontextmanager
async def lifespan(app: FastAPI):
    """Startup and shutdown events."""
    await initialize_accounts()
    yield
    # ИСПРАВЛЕНИЕ: при shutdown итерируемся по копии ключей,
    # т.к. remove_account мутирует словари во время обхода.
    for account_id in list(_clients.keys()):
        await remove_account(account_id)


app = FastAPI(
    title="NotebookLM FastAPI Service",
    description="FastAPI wrapper for notebooklm-py with account pool management",
    version="1.0.0",
    lifespan=lifespan
)

app.add_middleware(
    CORSMiddleware,
    allow_origins=["*"],  # Internal network only
    allow_credentials=True,
    allow_methods=["*"],
    allow_headers=["*"],
)


async def initialize_accounts():
    """Initialize clients from active accounts in database."""
    cookies_path = Path(os.getenv("COOKIES_PATH", "/cookies"))

    if not cookies_path.exists():
        print(f"[WARN] Cookies path {cookies_path} does not exist")
        return

    for account_dir in cookies_path.iterdir():
        if not account_dir.is_dir():
            continue

        account_id = account_dir.name
        storage_file = account_dir / "storage_state.json"

        if not storage_file.exists():
            continue

        try:
            await add_account(account_id, str(storage_file))
            print(f"[INFO] Initialized account {account_id}")
        except Exception as e:
            print(f"[ERROR] Failed to initialize account {account_id}: {e}")


async def add_account(account_id: str, storage_path: str) -> None:
    """Add an account to the pool."""
    keepalive = random.randint(450, 600)

    # ИСПРАВЛЕНИЕ: from_storage() вызывается БЕЗ await — это каноничный
    # способ (v0.5.0+). await от него вызывает DeprecationWarning и будет
    # удалён в v1.0.
    # from_storage() возвращает _FromStorageContext; __aenter__() возвращает
    # уже настоящий NotebookLMClient — его и нужно сохранять в пул.
    ctx = NotebookLMClient.from_storage(
        storage_path,
        keepalive=keepalive,
        rate_limit_max_retries=3,
        server_error_max_retries=3,
    )
    client = await ctx.__aenter__()

    _contexts[account_id] = ctx
    _clients[account_id] = client
    print(f"[INFO] Added account {account_id} with keepalive={keepalive}s")


async def remove_account(account_id: str) -> None:
    """Remove an account from the pool."""
    # ИСПРАВЛЕНИЕ: для симметрии с __aenter__ используем __aexit__,
    # а не client.close(). Это корректно закрывает HTTP-пул и сохраняет куки.
    ctx = _contexts.pop(account_id, None)
    _clients.pop(account_id, None)

    if ctx is not None:
        try:
            await ctx.__aexit__(None, None, None)
            print(f"[INFO] Closed client for account {account_id}")
        except Exception as e:
            print(f"[ERROR] Failed to close client for {account_id}: {e}")


def get_client(account_id: str) -> "NotebookLMClient":
    """Get client for account_id."""
    if account_id not in _clients:
        raise HTTPException(
            status_code=status.HTTP_404_NOT_FOUND,
            detail={
                "error": "AccountNotFoundError",
                "message": f"Account {account_id} not found in pool",
                "account_id": account_id,
                "response_time_ms": 0
            }
        )
    return _clients[account_id]


@app.get("/health")
async def health_check():
    """Basic health check."""
    return {"status": "ok", "clients_count": len(_clients)}


@app.get("/health/accounts")
async def health_accounts():
    """Check health of all accounts."""
    cookies_path = Path(os.getenv("COOKIES_PATH", "/cookies"))
    result = {}

    for account_id in _clients.keys():
        account_dir = cookies_path / account_id
        storage_file = account_dir / "storage_state.json"

        health_info = {
            "is_connected": account_id in _clients,
            "status": "healthy"
        }

        if storage_file.exists():
            mtime = storage_file.stat().st_mtime
            age = time.time() - mtime
            health_info["mtime_age_seconds"] = int(age)
            health_info["mtime"] = "healthy" if age < 600 else "stale"
        else:
            health_info["mtime"] = "missing"
            health_info["status"] = "degraded"

        result[account_id] = health_info

    return result


@app.post("/accounts/{account_id}")
async def create_account(account_id: str, request: Request):
    """Initialize a new account and add to pool."""
    cookies_path = Path(os.getenv("COOKIES_PATH", "/cookies"))
    storage_path = str(cookies_path / account_id / "storage_state.json")

    if not Path(storage_path).exists():
        raise HTTPException(
            status_code=status.HTTP_400_BAD_REQUEST,
            detail={
                "error": "AccountInitializationError",
                "message": f"storage_state.json not found for account {account_id}",
                "account_id": account_id,
                "response_time_ms": 0
            }
        )

    try:
        await add_account(account_id, storage_path)
        return {"status": "created", "account_id": account_id}
    except Exception as e:
        raise HTTPException(
            status_code=status.HTTP_500_INTERNAL_SERVER_ERROR,
            detail={
                "error": "AccountInitializationError",
                "message": f"Failed to initialize account: {str(e)}",
                "account_id": account_id,
                "response_time_ms": 0
            }
        )


@app.delete("/accounts/{account_id}")
async def delete_account(account_id: str):
    """Remove an account from the pool."""
    await remove_account(account_id)
    return {"status": "deleted", "account_id": account_id}


# Include API routers
from api.notebooks import router as notebooks_router
from api.sources import router as sources_router
from api.chat import router as chat_router
from api.settings import router as settings_router
from api.sharing import router as sharing_router

app.include_router(notebooks_router, prefix="/accounts/{account_id}", tags=["notebooks"])
app.include_router(sources_router, prefix="/accounts/{account_id}", tags=["sources"])
app.include_router(chat_router, prefix="/accounts/{account_id}", tags=["chat"])
app.include_router(settings_router, prefix="/accounts/{account_id}", tags=["settings"])
app.include_router(sharing_router, prefix="/accounts/{account_id}", tags=["sharing"])


@app.exception_handler(NotebookLMError)
async def notebooklm_error_handler(request: Request, exc: NotebookLMError):
    """Handle notebooklm-py errors."""
    # ИСПРАВЛЕНИЕ: был баг — сохранялся timestamp старта, а не elapsed time.
    start_time = getattr(request.state, "start_time", None)
    response_time_ms = int((time.time() - start_time) * 1000) if start_time else 0

    if isinstance(exc, RateLimitError):
        return JSONResponse(
            status_code=status.HTTP_429_TOO_MANY_REQUESTS,
            content={
                "error": "RateLimitError",
                "message": str(exc),
                "retry_after": getattr(exc, "retry_after", 60),
                "response_time_ms": response_time_ms
            }
        )
    elif isinstance(exc, AuthError):
        return JSONResponse(
            status_code=status.HTTP_503_SERVICE_UNAVAILABLE,
            content={
                "error": "AuthError",
                "message": "Account session expired",
                "response_time_ms": response_time_ms
            }
        )
    elif isinstance(exc, NetworkError):
        return JSONResponse(
            status_code=status.HTTP_503_SERVICE_UNAVAILABLE,
            content={
                "error": "NetworkError",
                "message": "NotebookLM unreachable",
                "response_time_ms": response_time_ms
            }
        )
    elif isinstance(exc, NotebookLimitError):
        return JSONResponse(
            status_code=status.HTTP_507_INSUFFICIENT_STORAGE,
            content={
                "error": "NotebookLimitError",
                "message": str(exc),
                "response_time_ms": response_time_ms
            }
        )
    else:
        return JSONResponse(
            status_code=status.HTTP_500_INTERNAL_SERVER_ERROR,
            content={
                "error": "NotebookLMError",
                "message": str(exc),
                "response_time_ms": response_time_ms
            }
        )


@app.middleware("http")
async def add_response_time(request: Request, call_next):
    """Add response time to all responses."""
    start_time = time.time()
    request.state.start_time = start_time

    response = await call_next(request)

    process_time = (time.time() - start_time) * 1000
    response.headers["X-Response-Time-Ms"] = str(int(process_time))

    return response


if __name__ == "__main__":
    import uvicorn
    uvicorn.run(
        "main:app",
        host="0.0.0.0",
        port=int(os.getenv("FASTAPI_PORT", "8000")),
        # workers=1 обязателен: NotebookLMClient привязан к event loop,
        # несколько воркеров форкнут процесс и сломают клиентов в пуле.
        workers=1,
        log_level="info"
    )