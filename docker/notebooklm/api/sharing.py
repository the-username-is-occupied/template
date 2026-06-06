"""
SharingAPI endpoints - wraps notebooklm-py SharingAPI.
"""
import time

from fastapi import APIRouter, Depends, HTTPException, Request
from main import get_client, app
from models import (
    SharingStatusResponse,
    ShareStatus,
    SharedUser,
)


router = APIRouter()


@router.get("/notebooks/{notebook_id}/sharing", response_model=SharingStatusResponse)
async def get_sharing_status(request: Request, account_id: str, notebook_id: str):
    """Get sharing status of a notebook."""
    client = get_client(account_id)
    
    status_obj = await client.sharing.get_status(notebook_id)
    
    # Map users to SharedUser objects if present
    shared_users = []
    if hasattr(status_obj, 'shared_users') and status_obj.shared_users:
        for user in status_obj.shared_users:
            shared_users.append(SharedUser(
                email=user.email if hasattr(user, 'email') else "",
                permission=user.permission if hasattr(user, 'permission') else "VIEWER",
                display_name=user.display_name if hasattr(user, 'display_name') else None,
                avatar_url=user.avatar_url if hasattr(user, 'avatar_url') else None
            ))
    
    status = ShareStatus(
        notebook_id=notebook_id,
        is_public=status_obj.is_public if hasattr(status_obj, 'is_public') else False,
        access=status_obj.access if hasattr(status_obj, 'access') else "RESTRICTED",
        view_level=status_obj.view_level if hasattr(status_obj, 'view_level') else "FULL_NOTEBOOK",
        shared_users=shared_users,
        share_url=status_obj.share_url if hasattr(status_obj, 'share_url') else None
    )
    
    start_time = int((time.time() - request.state.start_time) * 1000)
    
    return SharingStatusResponse(
        response_time_ms=start_time,
        status=status
    )


@router.post("/notebooks/{notebook_id}/sharing/public", response_model=SharingStatusResponse)
async def set_public(request: Request, account_id: str, notebook_id: str):
    """Set notebook as public."""
    client = get_client(account_id)
    
    status_obj = await client.sharing.set_public(notebook_id, True)
    
    # Map users to SharedUser objects if present
    shared_users = []
    if hasattr(status_obj, 'shared_users') and status_obj.shared_users:
        for user in status_obj.shared_users:
            shared_users.append(SharedUser(
                email=user.email if hasattr(user, 'email') else "",
                permission=user.permission if hasattr(user, 'permission') else "VIEWER",
                display_name=user.display_name if hasattr(user, 'display_name') else None,
                avatar_url=user.avatar_url if hasattr(user, 'avatar_url') else None
            ))
    
    status = ShareStatus(
        notebook_id=notebook_id,
        is_public=status_obj.is_public if hasattr(status_obj, 'is_public') else True,
        access=status_obj.access if hasattr(status_obj, 'access') else "ANYONE_WITH_LINK",
        view_level=status_obj.view_level if hasattr(status_obj, 'view_level') else "FULL_NOTEBOOK",
        shared_users=shared_users,
        share_url=status_obj.share_url if hasattr(status_obj, 'share_url') else None
    )
    
    start_time = int((time.time() - request.state.start_time) * 1000)
    
    return SharingStatusResponse(
        response_time_ms=start_time,
        status=status
    )


@router.post("/notebooks/{notebook_id}/sharing/private", response_model=SharingStatusResponse)
async def set_private(request: Request, account_id: str, notebook_id: str):
    """Set notebook as private."""
    client = get_client(account_id)
    
    status_obj = await client.sharing.set_public(notebook_id, False)
    
    # Map users to SharedUser objects if present
    shared_users = []
    if hasattr(status_obj, 'shared_users') and status_obj.shared_users:
        for user in status_obj.shared_users:
            shared_users.append(SharedUser(
                email=user.email if hasattr(user, 'email') else "",
                permission=user.permission if hasattr(user, 'permission') else "VIEWER",
                display_name=user.display_name if hasattr(user, 'display_name') else None,
                avatar_url=user.avatar_url if hasattr(user, 'avatar_url') else None
            ))
    
    status = ShareStatus(
        notebook_id=notebook_id,
        is_public=status_obj.is_public if hasattr(status_obj, 'is_public') else False,
        access=status_obj.access if hasattr(status_obj, 'access') else "RESTRICTED",
        view_level=status_obj.view_level if hasattr(status_obj, 'view_level') else "FULL_NOTEBOOK",
        shared_users=shared_users,
        share_url=status_obj.share_url if hasattr(status_obj, 'share_url') else None
    )
    
    start_time = int((time.time() - request.state.start_time) * 1000)
    
    return SharingStatusResponse(
        response_time_ms=start_time,
        status=status
    )
