"""NotebooksAPI endpoints — wraps notebooklm-py NotebooksAPI."""
from fastapi import APIRouter, Request

from models import (
    NotebookCreateRequest,
    NotebookListResponse,
    NotebookRenameRequest,
    NotebookResponse,
    NotebookDescriptionResponse,
    NotebookMetadataResponse,
)
from pool import get_client
from api.utils import elapsed_ms, map_notebook, pick

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


@router.get("/notebooks/{notebook_id}/description", response_model=NotebookDescriptionResponse)
async def get_notebook_description(request: Request, account_id: str, notebook_id: str):
    """Get AI-generated description for a notebook."""
    client = get_client(account_id)
    data = await client.notebooks.get_description(notebook_id)

    summary = pick(data, "summary", "")
    raw_topics = pick(data, "suggested_topics", []) or []
    suggested_topics = []
    for t in raw_topics:
        suggested_topics.append({
            "question": pick(t, "question", ""),
            "prompt": pick(t, "prompt", ""),
        })

    description = {"summary": summary, "suggested_topics": suggested_topics}
    return NotebookDescriptionResponse(response_time_ms=elapsed_ms(request), description=description)


@router.get("/notebooks/{notebook_id}/metadata", response_model=NotebookMetadataResponse)
async def get_notebook_metadata(request: Request, account_id: str, notebook_id: str):
    """Get metadata for a notebook."""
    client = get_client(account_id)
    data = await client.notebooks.get_metadata(notebook_id)

    raw_nb = pick(data, "notebook")
    mapped_nb = map_notebook(raw_nb)

    raw_sources = pick(data, "sources", []) or []
    sources = []
    for s in raw_sources:
        kind_raw = pick(s, "kind")
        # try unwrapping enum-like or dict wrappers
        kind = pick(kind_raw, "_value_", pick(kind_raw, "value", kind_raw))
        sources.append(
            {
                "id": pick(s, "id"),
                "kind": kind,
                "title": pick(s, "title"),
                "url": pick(s, "url"),
            }
        )

    metadata = {"notebook": mapped_nb, "sources": sources}
    return NotebookMetadataResponse(response_time_ms=elapsed_ms(request), metadata=metadata)
