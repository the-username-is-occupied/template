<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\ExtractionStatus;
use App\Enums\SourceDraftStatus;
use App\Events\TelegramParsingDone;
use App\Models\ContentSource;
use App\Models\SourceDraft;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class TelegramScrapingDoneJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public string $contentSourceId,
    ) {}

    public function handle(): void
    {
        $source = ContentSource::with('sourceDrafts')->find($this->contentSourceId);

        if (! $source) {
            Log::error('ContentSource not found for scraping done', [
                'content_source_id' => $this->contentSourceId,
            ]);

            return;
        }

        // Update extraction status
        $source->update([
            'extraction_status' => ExtractionStatus::Extracted,
        ]);

        // Find related SourceDraft and update status
        $draft = $source->sourceDrafts()->first();

        if ($draft) {
            $draft->update([
                'status' => SourceDraftStatus::AwaitingIndex,
            ]);

            Log::info('Updated SourceDraft status to awaiting_index', [
                'source_draft_id' => $draft->id,
                'content_source_id' => $this->contentSourceId,
            ]);
        } else {
            Log::warning('No SourceDraft found for ContentSource', [
                'content_source_id' => $this->contentSourceId,
            ]);
        }

        // Get statistics
        $totalPosts = $source->originalItems()->count();
        $totalLinks = ContentSource::where('parent_source_id', $source->id)->count();

        Log::info('Telegram scraping completed', [
            'content_source_id' => $this->contentSourceId,
            'total_posts' => $totalPosts,
            'total_links' => $totalLinks,
        ]);

        // Dispatch SSE event
        if ($draft) {
            TelegramParsingDone::dispatch($draft, $totalPosts, $totalLinks);
        }
    }
}
