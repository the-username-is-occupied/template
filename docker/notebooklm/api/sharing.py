"""
SharingAPI endpoints - wraps notebooklm-py SharingAPI.
"""
import time

from fastapi import APIRouter, Depends, HTTPException, Request
from main import get_client, app
from models import (
    SharingStatusResponse,
    ShareStatus,
)


router = APIRouter()


@router.get("/notebooks/{notebook_id}/sharing", response_model=SharingStatusResponse)
async def get_sharing_status(request: Request, account_id: str, notebook_id: str):
    """Get sharing status of a notebook."""
    client = get_client(account_id)
    
    status_data = await client.sharing.get_status(notebook_id)
    
    status = ShareStatus(
        is_public=status_data.get("is_public", False),
        view_level=status_data.get("view_level", "private"),
        users=status_data.get("users", [])
    )
    
    start_time = int((time.time() - request.state.start_time) * 1000)
    
    return SharingStatusResponse(
        response_time_ms=start_time,
        status=status
    )


@router.post("/notebooks/{notebook_id}/sharing/public")
async def set_public(request: Request, account_id: str, notebook_id: str):
    """Set notebook as public."""
    client = get_client(account_id)
    
    await client.sharing.set_public(notebook_id)
    
    start_time = int((time.time() - request.state.start_time) * 1000)
    
    return {
        "response_time_ms": start_time,
        "success": True
    }


@router.post("/notebooks/{notebook_id}/sharing/private")
async def set_private(request: Request, account_id: str, notebook_id: str):
    """Set notebook as private."""
    client = get_client(account_id)
    
    await client.sharing.set_private(notebook_id)
    
    start_time = int((time.time() - request.state.start_time) * 1000)
    
    return {
        "response_time_ms": start_time,
        "success": True
    }
