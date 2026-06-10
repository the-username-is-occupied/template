from pydantic import BaseModel
from typing import Optional


class ExtractRequest(BaseModel):
    url: str


class VideoEntry(BaseModel):
    id: str
    url: str
    title: Optional[str] = None
    duration: Optional[int] = None
    view_count: Optional[int] = None
    upload_date: Optional[str] = None
    thumbnail: Optional[str] = None
    availability: Optional[str] = None
    channel: Optional[str] = None
    channel_id: Optional[str] = None


class ExtractResponse(BaseModel):
    type: str  # 'playlist', 'channel', 'video'
    id: Optional[str] = None
    title: Optional[str] = None
    uploader: Optional[str] = None
    uploader_id: Optional[str] = None
    uploader_url: Optional[str] = None
    channel_id: Optional[str] = None
    webpage_url: Optional[str] = None
    description: Optional[str] = None
    video_count: int = 0
    videos: list[VideoEntry] = []
