"""
NotebooksAPI endpoints - wraps notebooklm-py NotebooksAPI.
"""
from typing import List
import time

from fastapi import APIRouter, Depends, HTTPException, Request
from main import get_client, app
from models import (
    Notebook,
    NotebookListResponse,
    NotebookResponse,
    NotebookCreateRequest,
    NotebookRenameRequest,
)


router = APIRouter()


@router.get("/notebooks", response_model=NotebookListResponse)
async def list_notebooks(request: Request, account_id: str):
    """List all notebooks for account."""
    client = get_client(account_id)
    
    notebooks_data = await client.notebooks.list()
    
    notebooks = []
    for nb in notebooks_data:
        # Handle both dict and object returns from notebooklm-py
        if isinstance(nb, dict):
            notebook = Notebook(
                id=nb.get("id"),
                title=nb.get("title"),
                created_at=nb.get("created_at"),
                sources_count=nb.get("sources_count", 0),
                is_owner=nb.get("is_owner", True)
            )
        else:
            # Object with attributes
            notebook = Notebook(
                id=getattr(nb, 'id', None),
                title=getattr(nb, 'title', None),
                created_at=getattr(nb, 'created_at', None),
                sources_count=getattr(nb, 'sources_count', 0),
                is_owner=getattr(nb, 'is_owner', True)
            )
        notebooks.append(notebook)
    
    start_time = int((time.time() - request.state.start_time) * 1000)
    
    return NotebookListResponse(
        response_time_ms=start_time,
        notebooks=notebooks
    )


@router.post("/notebooks", response_model=NotebookResponse)
async def create_notebook(
    request: Request,
    account_id: str,
    body: NotebookCreateRequest
):
    """Create a new notebook."""
    client = get_client(account_id)
    
    notebook_data = await client.notebooks.create(title=body.title)
    
    # Handle both dict and object returns
    if isinstance(notebook_data, dict):
        notebook = Notebook(
            id=notebook_data.get("id"),
            title=notebook_data.get("title"),
            created_at=notebook_data.get("created_at"),
            sources_count=notebook_data.get("sources_count", 0),
            is_owner=notebook_data.get("is_owner", True)
        )
    else:
        notebook = Notebook(
            id=getattr(notebook_data, 'id', None),
            title=getattr(notebook_data, 'title', None),
            created_at=getattr(notebook_data, 'created_at', None),
            sources_count=getattr(notebook_data, 'sources_count', 0),
            is_owner=getattr(notebook_data, 'is_owner', True)
        )
    
    start_time = int((time.time() - request.state.start_time) * 1000)
    
    return NotebookResponse(
        response_time_ms=start_time,
        notebook=notebook
    )


@router.get("/notebooks/{notebook_id}", response_model=NotebookResponse)
async def get_notebook(request: Request, account_id: str, notebook_id: str):
    """Get notebook details."""
    client = get_client(account_id)
    
    notebook_data = await client.notebooks.get(notebook_id)
    
    # Handle both dict and object returns
    if isinstance(notebook_data, dict):
        notebook = Notebook(
            id=notebook_data.get("id"),
            title=notebook_data.get("title"),
            created_at=notebook_data.get("created_at"),
            sources_count=notebook_data.get("sources_count", 0),
            is_owner=notebook_data.get("is_owner", True)
        )
    else:
        notebook = Notebook(
            id=getattr(notebook_data, 'id', None),
            title=getattr(notebook_data, 'title', None),
            created_at=getattr(notebook_data, 'created_at', None),
            sources_count=getattr(notebook_data, 'sources_count', 0),
            is_owner=getattr(notebook_data, 'is_owner', True)
        )
    
    start_time = int((time.time() - request.state.start_time) * 1000)
    
    return NotebookResponse(
        response_time_ms=start_time,
        notebook=notebook
    )


@router.put("/notebooks/{notebook_id}", response_model=NotebookResponse)
async def rename_notebook(
    request: Request,
    account_id: str,
    notebook_id: str,
    body: NotebookRenameRequest
):
    """Rename a notebook."""
    client = get_client(account_id)
    
    notebook_data = await client.notebooks.rename(notebook_id, body.title)
    
    # Handle both dict and object returns
    if isinstance(notebook_data, dict):
        notebook = Notebook(
            id=notebook_data.get("id"),
            title=notebook_data.get("title"),
            created_at=notebook_data.get("created_at"),
            sources_count=notebook_data.get("sources_count", 0),
            is_owner=notebook_data.get("is_owner", True)
        )
    else:
        notebook = Notebook(
            id=getattr(notebook_data, 'id', None),
            title=getattr(notebook_data, 'title', None),
            created_at=getattr(notebook_data, 'created_at', None),
            sources_count=getattr(notebook_data, 'sources_count', 0),
            is_owner=getattr(notebook_data, 'is_owner', True)
        )
    
    start_time = int((time.time() - request.state.start_time) * 1000)
    
    return NotebookResponse(
        response_time_ms=start_time,
        notebook=notebook
    )


@router.delete("/notebooks/{notebook_id}")
async def delete_notebook(request: Request, account_id: str, notebook_id: str):
    """Delete a notebook."""
    client = get_client(account_id)
    
    await client.notebooks.delete(notebook_id)
    
    start_time = int((time.time() - request.state.start_time) * 1000)
    
    return {
        "response_time_ms": start_time,
        "success": True
    }
