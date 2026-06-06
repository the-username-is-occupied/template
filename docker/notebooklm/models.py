"""
Pydantic models for NotebookLM FastAPI service.
Matches notebooklm-py Data Types from python-api.md.
"""
from typing import Optional, List, Dict, Any
from pydantic import BaseModel, Field
from datetime import datetime


# Base response with response time
class BaseResponse(BaseModel):
    response_time_ms: int = Field(default=0, description="Request processing time in milliseconds")


# Notebook models
class Notebook(BaseModel):
    id: str = Field(..., description="Notebook ID")
    title: str = Field(..., description="Notebook title")
    created_at: datetime = Field(..., description="Creation timestamp")
    sources_count: int = Field(default=0, description="Number of sources")
    is_owner: bool = Field(default=True, description="Whether current user is owner")


class NotebookListResponse(BaseResponse):
    notebooks: List[Notebook] = Field(..., description="List of notebooks")


class NotebookResponse(BaseResponse):
    notebook: Notebook = Field(..., description="Notebook details")


class NotebookCreateRequest(BaseModel):
    title: str = Field(..., description="Notebook title", min_length=1, max_length=255)


class NotebookRenameRequest(BaseModel):
    title: str = Field(..., description="New notebook title", min_length=1, max_length=255)


# Source models
class Source(BaseModel):
    id: str = Field(..., description="Source ID")
    title: str = Field(..., description="Source title")
    url: Optional[str] = Field(None, description="Source URL")
    created_at: datetime = Field(..., description="Creation timestamp")
    status: str = Field(..., description="Processing status")
    kind: str = Field(..., description="Source type: pdf, web_page, etc.")
    is_ready: bool = Field(default=False, description="Whether source is ready")
    is_processing: bool = Field(default=False, description="Whether source is processing")
    is_error: bool = Field(default=False, description="Whether source has error")


class SourceListResponse(BaseResponse):
    sources: List[Source] = Field(..., description="List of sources")


class SourceResponse(BaseResponse):
    source: Source = Field(..., description="Source details")


class SourceFulltextResponse(BaseResponse):
    fulltext: str = Field(..., description="Source full text")
    format: str = Field(default="markdown", description="Text format")


# Chat models
class AskRequest(BaseModel):
    notebook_id: str = Field(..., description="Notebook ID")
    question: str = Field(..., description="Question to ask")
    source_ids: Optional[List[str]] = Field(None, description="Optional source IDs to reference")
    conversation_id: Optional[str] = Field(None, description="Optional conversation ID for follow-up")


class ChatReference(BaseModel):
    source_id: str = Field(..., description="Source ID")
    citation_number: int = Field(..., description="Citation number")
    cited_text: str = Field(..., description="Cited text")
    start_char: int = Field(..., description="Start character position")
    end_char: int = Field(..., description="End character position")
    chunk_id: Optional[str] = Field(None, description="Chunk ID")


class AskResult(BaseModel):
    answer: str = Field(..., description="Answer text")
    conversation_id: str = Field(..., description="Conversation ID")
    turn_number: int = Field(..., description="Turn number")
    is_follow_up: bool = Field(default=False, description="Whether this is a follow-up question")
    references: List[ChatReference] = Field(default_factory=list, description="Citations")


class AskResponse(BaseResponse):
    result: AskResult = Field(..., description="Ask result")


# Settings models
class AccountLimits(BaseModel):
    notebooks_limit: int = Field(..., description="Max notebooks")
    sources_per_notebook_limit: int = Field(..., description="Max sources per notebook")
    chats_per_day_limit: int = Field(..., description="Max chats per day")


class AccountTier(BaseModel):
    tier: str = Field(..., description="Account tier: free, pro, etc.")
    is_paid: bool = Field(default=False, description="Whether account has paid tier")


class SettingsResponse(BaseResponse):
    output_language: str = Field(..., description="Current output language")
    account_limits: AccountLimits = Field(..., description="Account limits")
    account_tier: AccountTier = Field(..., description="Account tier info")


# Sharing models
class ShareStatus(BaseModel):
    is_public: bool = Field(default=False, description="Whether notebook is public")
    view_level: str = Field(..., description="View level: public, restricted, private")
    users: List[Dict[str, Any]] = Field(default_factory=list, description="Shared users")


class SharingStatusResponse(BaseResponse):
    status: ShareStatus = Field(..., description="Sharing status")


# Health models
class AccountHealth(BaseModel):
    mtime_age_seconds: Optional[int] = Field(None, description="Seconds since storage_state.json was modified")
    mtime: str = Field(..., description="Health status: healthy, stale, missing")
    is_connected: bool = Field(..., description="Whether client is connected")
    status: str = Field(default="healthy", description="Overall status")


class HealthAccountsResponse(BaseModel):
    accounts: Dict[str, AccountHealth] = Field(..., description="Account health status")


# Error models
class ErrorResponse(BaseModel):
    error: str = Field(..., description="Error type name")
    message: str = Field(..., description="Error message")
    account_id: Optional[str] = Field(None, description="Account ID (if applicable)")
    response_time_ms: int = Field(..., description="Response time in milliseconds")
    retry_after: Optional[int] = Field(None, description="Retry after seconds (for RateLimitError)")
