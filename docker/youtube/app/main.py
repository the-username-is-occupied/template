from fastapi import FastAPI
from fastapi.responses import RedirectResponse
from app.routers import scraper

app = FastAPI(
    title="YT Scraper API",
    description="Extract video URLs and metadata from YouTube channels and playlists via yt-dlp",
    version="1.0.0",
)

app.include_router(scraper.router, prefix="/api/v1", tags=["scraper"])


@app.get("/", include_in_schema=False)
def root():
    return RedirectResponse(url="/docs")


@app.get("/health", tags=["system"])
def health():
    return {"status": "ok"}