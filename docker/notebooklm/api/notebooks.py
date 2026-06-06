"""NotebooksAPI endpoints — wraps notebooklm-py NotebooksAPI."""
from fastapi import APIRouter, Request

from models import NotebookCreateRequest, NotebookListResponse, NotebookRenameRequest, NotebookResponse
from pool import get_client
from api.utils import elapsed_ms, map_notebook

router = APIRouter()


@router.get("/notebooks", response_model=NotebookListResponse)
async def list_notebooks(request: Request, account_id: str):
    """List all notebooks for the account."""
    client = get_client(account_id)
    notebooks = [map_notebook(nb) for nb in await client.notebooks.list()]
    return NotebookListResponse(response_time_ms=elapsed_ms(request), notebooks=notebooks)


@router.post("/notebooks", response_model=NotebookResponse)
async def create_notebook(request: Request, account_id: str, body: NotebookCreateRequest):
    """Create a new notebook."""
    client = get_client(account_id)
    data = await client.notebooks.create(title=body.title)
    return NotebookResponse(response_time_ms=elapsed_ms(request), notebook=map_notebook(data))


@router.get("/notebooks/{notebook_id}", response_model=NotebookResponse)
async def get_notebook(request: Request, account_id: str, notebook_id: str):
    """Get notebook details."""
    client = get_client(account_id)
    data = await client.notebooks.get(notebook_id)
    return NotebookResponse(response_time_ms=elapsed_ms(request), notebook=map_notebook(data))


@router.put("/notebooks/{notebook_id}", response_model=NotebookResponse)
async def rename_notebook(
    request: Request,
    account_id: str,
    notebook_id: str,
    body: NotebookRenameRequest,
):
    """Rename a notebook."""
    client = get_client(account_id)
    data = await client.notebooks.rename(notebook_id, body.title)
    return NotebookResponse(response_time_ms=elapsed_ms(request), notebook=map_notebook(data))


@router.delete("/notebooks/{notebook_id}")
async def delete_notebook(request: Request, account_id: str, notebook_id: str):
    """Delete a notebook."""
    client = get_client(account_id)
    await client.notebooks.delete(notebook_id)
    return {"response_time_ms": elapsed_ms(request), "success": True}
