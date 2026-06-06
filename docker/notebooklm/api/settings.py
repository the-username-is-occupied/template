"""SettingsAPI endpoints — wraps notebooklm-py SettingsAPI."""
from fastapi import APIRouter, Request

from models import AccountLimits, AccountTier, SettingsResponse
from pool import get_client
from api.utils import elapsed_ms, pick

router = APIRouter()


@router.get("/settings", response_model=SettingsResponse)
async def get_settings(request: Request, account_id: str):
    """Get account settings, limits, and tier."""
    client = get_client(account_id)

    output_language = await client.settings.get_output_language()
    limits_data = await client.settings.get_account_limits()
    tier_data = await client.settings.get_account_tier()

    return SettingsResponse(
        response_time_ms=elapsed_ms(request),
        output_language=output_language,
        account_limits=AccountLimits(
            notebooks_limit=pick(limits_data, "notebooks_limit", 0),
            sources_per_notebook_limit=pick(limits_data, "sources_per_notebook_limit", 0),
            chats_per_day_limit=pick(limits_data, "chats_per_day_limit", 0),
        ),
        account_tier=AccountTier(
            tier=pick(tier_data, "tier", "free"),
            is_paid=pick(tier_data, "is_paid", False),
        ),
    )


@router.post("/settings/language/{language}")
async def set_output_language(request: Request, account_id: str, language: str):
    """Set the output language for the account."""
    client = get_client(account_id)
    await client.settings.set_output_language(language)
    return {"response_time_ms": elapsed_ms(request), "success": True}
