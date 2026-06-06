"""SharingAPI endpoints — wraps notebooklm-py SharingAPI."""
from fastapi import APIRouter, Request

from models import SharingStatusResponse
from pool import get_client
from api.utils import elapsed_ms, map_share_status

router = APIRouter()


@router.get("/notebooks/{notebook_id}/sharing", response_model=SharingStatusResponse)
async def get_sharing_status(request: Request, account_id: str, notebook_id: str):
    """Get the sharing status of a notebook."""
    client = get_client(account_id)
    status_obj = await client.sharing.get_status(notebook_id)
    return SharingStatusResponse(
        response_time_ms=elapsed_ms(request),
        status=map_share_status(notebook_id, status_obj),
    )


@router.post("/notebooks/{notebook_id}/sharing/public", response_model=SharingStatusResponse)
async def set_public(request: Request, account_id: str, notebook_id: str):
    """Make the notebook publicly accessible via link."""
    client = get_client(account_id)
    status_obj = await client.sharing.set_public(notebook_id, True)
    return SharingStatusResponse(
        response_time_ms=elapsed_ms(request),
        status=map_share_status(notebook_id, status_obj),
    )


@router.post("/notebooks/{notebook_id}/sharing/private", response_model=SharingStatusResponse)
async def set_private(request: Request, account_id: str, notebook_id: str):
    """Restrict the notebook to explicitly shared users only."""
    client = get_client(account_id)
    status_obj = await client.sharing.set_public(notebook_id, False)
    return SharingStatusResponse(
        response_time_ms=elapsed_ms(request),
        status=map_share_status(notebook_id, status_obj),
    )
