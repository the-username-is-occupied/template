"""
Pydantic models for NotebookLM FastAPI service.
Matches notebooklm-py data types.
"""
from __future__ import annotations

from datetime import datetime
from typing import Any, Dict, List, Optional

from pydantic import BaseModel, Field


# ---------------------------------------------------------------------------
# Base
# ---------------------------------------------------------------------------

class BaseResponse(BaseModel):
    response_time_ms: int = Field(default=0, description="Request processing time in milliseconds")


# ---------------------------------------------------------------------------
# Notebooks
# ---------------------------------------------------------------------------

class Notebook(BaseModel):
    id: str = Field(..., description="Notebook ID")
    title: str = Field(..., description="Notebook title")
    created_at: Optional[datetime] = Field(None, description="Creation timestamp")
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


# ---------------------------------------------------------------------------
# Sources
# ---------------------------------------------------------------------------

class Source(BaseModel):
    id: str = Field(..., description="Source ID")
    title: str = Field(..., description="Source title")
    url: Optional[str] = Field(None, description="Source URL")
    # created_at / status / kind can be absent on partially-ingested sources
    created_at: Optional[datetime] = Field(None, description="Creation timestamp")
    status: Optional[str] = Field(None, description="Processing status")
    kind: Optional[str] = Field(None, description="Source type: pdf, web_page, etc.")
    is_ready: bool = Field(default=False, description="Whether source is ready")
    is_processing: bool = Field(default=False, description="Whether source is processing")
    is_error: bool = Field(default=False, description="Whether source has an error")


class SourceListResponse(BaseResponse):
    sources: List[Source] = Field(..., description="List of sources")


class SourceResponse(BaseResponse):
    source: Source = Field(..., description="Source details")


class SourceFulltextResponse(BaseResponse):
    source_id: str = Field(..., description="Source ID")
    title: str = Field(..., description="Source title")
    content: str = Field(..., description="Full indexed text content")
    url: Optional[str] = Field(None, description="Original URL if applicable")
    char_count: int = Field(..., description="Character count")
    format: str = Field(default="markdown", description="Text format")


class SourceGuideResponse(BaseResponse):
    summary: str = Field(..., description="AI-generated summary")
    keywords: List[str] = Field(..., description="AI-generated keywords")


class SourceRenameResponse(BaseResponse):
    source: Source = Field(..., description="Renamed source")


class SourceAddUrlRequest(BaseModel):
    url: str = Field(..., description="URL to add as source")


class SourceAddUrlResponse(BaseResponse):
    source: Source = Field(..., description="Added URL source")


class SourceAddTextRequest(BaseModel):
    text: str = Field(..., description="Text content to add")
    title: str = Field(..., description="Title for the text source")


class SourceAddTextResponse(BaseResponse):
    source: Source = Field(..., description="Added text source")


class SourceAddFileRequest(BaseModel):
    file_path: str = Field(..., description="Path to file to upload")
    title: Optional[str] = Field(None, description="Display title for the source")
    wait: bool = Field(default=False, description="Wait for source to be ready")
    wait_timeout: float = Field(default=120.0, description="Timeout for wait")


class SourceAddFileResponse(BaseResponse):
    source: Source = Field(..., description="Uploaded source")


class SourceWaitRegisteredResponse(BaseResponse):
    source: Source = Field(..., description="Registered source")


class SourcesWaitMultipleRequest(BaseModel):
    source_ids: List[str] = Field(..., description="List of source IDs to wait for")
    timeout: float = Field(default=120.0, description="Timeout in seconds")


class SourcesWaitMultipleResponse(BaseResponse):
    sources: List[Source] = Field(..., description="List of ready sources")


class SourceRefreshResponse(BaseResponse):
    success: bool = Field(..., description="Whether refresh was successful")


class SourceFreshnessResponse(BaseResponse):
    is_fresh: bool = Field(..., description="Whether source needs refresh")


# ---------------------------------------------------------------------------
# Chat
# ---------------------------------------------------------------------------

class ConfigureChatRequest(BaseModel):
    custom_prompt: str = Field(..., description="Custom system prompt for the chat")


class AskRequest(BaseModel):
    notebook_id: str = Field(..., description="Notebook ID")
    question: str = Field(..., description="Question to ask")
    source_ids: Optional[List[str]] = Field(None, description="Source IDs to scope the query")
    conversation_id: Optional[str] = Field(None, description="Conversation ID for follow-up turns")


class ChatReference(BaseModel):
    # All fields are optional: notebooklm-py may omit any of them depending
    # on the source type and whether the text was grounded in a citation.
    source_id: Optional[str] = Field(None, description="Source ID")
    citation_number: Optional[int] = Field(None, description="Citation number")
    cited_text: Optional[str] = Field(None, description="Cited text excerpt")
    start_char: Optional[int] = Field(None, description="Start character position")
    end_char: Optional[int] = Field(None, description="End character position")
    chunk_id: Optional[str] = Field(None, description="Chunk ID")


class AskResult(BaseModel):
    answer: str = Field(..., description="Answer text")
    conversation_id: str = Field(..., description="Conversation ID")
    turn_number: int = Field(..., description="Turn number in the conversation")
    is_follow_up: bool = Field(default=False, description="Whether this is a follow-up question")
    references: List[ChatReference] = Field(default_factory=list, description="Citations")


class AskResponse(BaseResponse):
    result: AskResult = Field(..., description="Ask result")


# ---------------------------------------------------------------------------
# Settings
# ---------------------------------------------------------------------------

class AccountLimits(BaseModel):
    notebooks_limit: int = Field(..., description="Max notebooks")
    sources_per_notebook_limit: int = Field(..., description="Max sources per notebook")
    chats_per_day_limit: int = Field(..., description="Max chats per day")


class AccountTier(BaseModel):
    tier: str = Field(..., description="Account tier: free, pro, etc.")
    is_paid: bool = Field(default=False, description="Whether account has a paid tier")


class SettingsResponse(BaseResponse):
    output_language: Optional[str] = Field(None, description="Current output language")
    account_limits: AccountLimits = Field(..., description="Account limits")
    account_tier: AccountTier = Field(..., description="Account tier info")


# ---------------------------------------------------------------------------
# Sharing
# ---------------------------------------------------------------------------

class SharedUser(BaseModel):
    email: str = Field(..., description="User email address")
    permission: str = Field(..., description="Permission level: OWNER, EDITOR, or VIEWER")
    display_name: Optional[str] = Field(None, description="User display name")
    avatar_url: Optional[str] = Field(None, description="URL to user avatar")


class ShareStatus(BaseModel):
    notebook_id: str = Field(..., description="Notebook ID")
    is_public: bool = Field(default=False, description="Whether publicly accessible")
    access: str = Field(..., description="Access level: RESTRICTED or ANYONE_WITH_LINK")
    view_level: str = Field(..., description="View level: FULL_NOTEBOOK or CHAT_ONLY")
    shared_users: List[SharedUser] = Field(default_factory=list, description="Users with explicit access")
    share_url: Optional[str] = Field(None, description="Public share URL (when is_public=True)")


class SharingStatusResponse(BaseResponse):
    status: ShareStatus = Field(..., description="Sharing status")


# ---------------------------------------------------------------------------
# Health
# ---------------------------------------------------------------------------

class AccountHealth(BaseModel):
    mtime_age_seconds: Optional[int] = Field(None, description="Seconds since storage_state.json was last modified")
    mtime: str = Field(..., description="Cookie freshness: healthy, stale, or missing")
    is_connected: bool = Field(..., description="Whether the client is in the pool")
    status: str = Field(default="healthy", description="Overall status")


class HealthAccountsResponse(BaseModel):
    accounts: Dict[str, AccountHealth] = Field(..., description="Per-account health status")


# ---------------------------------------------------------------------------
# Errors
# ---------------------------------------------------------------------------

class ErrorResponse(BaseModel):
    error: str = Field(..., description="Error type name")
    message: str = Field(..., description="Error message")
    account_id: Optional[str] = Field(None, description="Account ID (if applicable)")
    response_time_ms: int = Field(..., description="Response time in milliseconds")
    retry_after: Optional[int] = Field(None, description="Retry-after seconds (RateLimitError only)")


# ---------------------------------------------------------------------------
# Notebook description / metadata
# ---------------------------------------------------------------------------


class SuggestedTopic(BaseModel):
    question: str = Field(..., description="Suggested question for the notebook")
    prompt: str = Field(..., description="Generated prompt text for the suggested question")


class NotebookDescription(BaseModel):
    summary: str = Field(..., description="AI-generated summary text")
    suggested_topics: List[SuggestedTopic] = Field(default_factory=list, description="Suggested follow-up topics/questions")


class NotebookDescriptionResponse(BaseResponse):
    description: NotebookDescription = Field(..., description="Notebook description")


class NotebookMetadataSource(BaseModel):
    id: Optional[str] = Field(None, description="Optional source ID")
    kind: Optional[str] = Field(None, description="Source kind, e.g., youtube, web_page")
    title: Optional[str] = Field(None, description="Source title")
    url: Optional[str] = Field(None, description="Source URL")


class NotebookMetadata(BaseModel):
    notebook: Notebook = Field(..., description="Notebook brief info")
    sources: List[NotebookMetadataSource] = Field(default_factory=list, description="List of notebook sources")


class NotebookMetadataResponse(BaseResponse):
    metadata: NotebookMetadata = Field(..., description="Notebook metadata")
