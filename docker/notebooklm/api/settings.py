"""
SettingsAPI endpoints - wraps notebooklm-py SettingsAPI.
"""
import time

from fastapi import APIRouter, Depends, HTTPException, Request
from main import get_client, app
from models import (
    SettingsResponse,
    AccountLimits,
    AccountTier,
)


router = APIRouter()


@router.get("/settings", response_model=SettingsResponse)
async def get_settings(request: Request, account_id: str):
    """Get account settings."""
    client = get_client(account_id)
    
    output_language = await client.settings.get_output_language()
    account_limits = await client.settings.get_account_limits()
    account_tier = await client.settings.get_account_tier()
    
    limits = AccountLimits(
        notebooks_limit=account_limits.get("notebooks_limit", 0),
        sources_per_notebook_limit=account_limits.get("sources_per_notebook_limit", 0),
        chats_per_day_limit=account_limits.get("chats_per_day_limit", 0)
    )
    
    tier = AccountTier(
        tier=account_tier.get("tier", "free"),
        is_paid=account_tier.get("is_paid", False)
    )
    
    start_time = int((time.time() - request.state.start_time) * 1000)
    
    return SettingsResponse(
        response_time_ms=start_time,
        output_language=output_language,
        account_limits=limits,
        account_tier=tier
    )


@router.post("/settings/language/{language}")
async def set_output_language(request: Request, account_id: str, language: str):
    """Set output language."""
    client = get_client(account_id)
    
    await client.settings.set_output_language(language)
    
    start_time = int((time.time() - request.state.start_time) * 1000)
    
    return {
        "response_time_ms": start_time,
        "success": True
    }
