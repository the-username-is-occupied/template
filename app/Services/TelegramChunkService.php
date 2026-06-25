<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\ExtractionStatus;
use App\Enums\SourceDraftStatus;
use App\Events\TelegramLinksDiscovered;
use App\Events\TelegramParsingDone;
use App\Events\TelegramParsingProgress;
use App\Models\ContentSource;
use App\Models\OriginalItem;
use App\Models\SourceDraft;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

class TelegramChunkService
{
    private const REDIS_KEY_PENDING_CHUNKS = 'telegram:source:%s:pending_chunks';

    private const REDIS_KEY_SCRAPING_DONE = 'telegram:source:%s:scraping_done';

    /**
     * Process a chunk of Telegram posts
     *
     * @param  array<int, array<string, mixed>>  $posts
     * @return array{processedCount: int, linksDiscovered: array<int, ContentSource>}
     */
    public function processChunk(ContentSource $source, array $posts): array
    {
        if ($source->extraction_status->value !== 'uploading') {
            Log::warning('ContentSource is not in uploading status, skipping chunk', [
                'content_source_id' => $source->id,
                'status' => $source->extraction_status->value,
            ]);

            return ['processedCount' => 0, 'linksDiscovered' => []];
        }

        $processedCount = 0;
        $linksDiscovered = [];

        foreach ($posts as $post) {
            $originalItem = $this->processPost($source, $post);

            if ($originalItem) {
                $processedCount++;

                // Process links from this post
                $links = $post['links'] ?? [];
                if (! empty($links)) {
                    $processedLinks = app(LinkProcessorService::class)
                        ->processLinks($source, $originalItem, $links);

                    $linksDiscovered = array_merge($linksDiscovered, $processedLinks);
                }
            }
        }

        Log::info('Processed Telegram chunk', [
            'content_source_id' => $source->id,
            'posts_processed' => $processedCount,
            'links_discovered' => count($linksDiscovered),
        ]);

        // Dispatch SSE events
        $draft = $source->sourceDrafts()->first();

        if ($draft) {
            // parsing_progress event
            TelegramParsingProgress::dispatch($draft, $processedCount, count($linksDiscovered));

            // links_batch event if we discovered new links
            if (! empty($linksDiscovered)) {
                $linkData = array_map(fn ($linkSource) => [
                    'id' => $linkSource->id,
                    'url' => $linkSource->url,
                    'type' => $linkSource->type->value,
                ], $linksDiscovered);

                TelegramLinksDiscovered::dispatch($draft, $linkData);
            }
        }

        return ['processedCount' => $processedCount, 'linksDiscovered' => $linksDiscovered];
    }

    /**
     * Finalize chunk processing - decrement counter and check if all chunks are done
     */
    public function finalizeChunk(ContentSource $source): void
    {
        $contentSourceId = $source->id;

        // Atomically decrement pending chunks counter
        $newCount = $this->decrementPendingChunks($contentSourceId);

        Log::debug('Finalizing chunk', [
            'content_source_id' => $contentSourceId,
            'pending_chunks' => $newCount,
            'scraping_done' => $this->isScrapingDone($contentSourceId),
        ]);

        // Check if scraping is done and no pending chunks
        if ($newCount <= 0 && $this->isScrapingDone($contentSourceId)) {
            Log::info('All chunks processed and scraping done, executing done logic', [
                'content_source_id' => $contentSourceId,
            ]);

            $this->executeDoneLogic($source);
        }
    }

    /**
     * Execute the done logic (previously in TelegramScrapingDoneJob)
     */
    public function executeDoneLogic(ContentSource $source): void
    {
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

            $draft->contentSource->notebooks()->syncWithoutDetaching([$draft->knowledgeBase->id]);
            Log::info('Updated SourceDraft status to awaiting_index', [
                'source_draft_id' => $draft->id,
                'content_source_id' => $source->id,
            ]);
        } else {
            Log::warning('No SourceDraft found for ContentSource', [
                'content_source_id' => $source->id,
            ]);
        }

        // Get statistics
        $totalPosts = $source->originalItems()->count();
        $totalLinks = ContentSource::where('parent_source_id', $source->id)->count();

        Log::info('Telegram scraping completed', [
            'content_source_id' => $source->id,
            'total_posts' => $totalPosts,
            'total_links' => $totalLinks,
        ]);

        // Dispatch SSE event
        if ($draft) {
            TelegramParsingDone::dispatch($draft, $totalPosts, $totalLinks);
        }

        // Clean up Redis keys
        $this->cleanupRedisKeys($source->id);
    }

    /**
     * Increment pending chunks counter in Redis
     */
    public function incrementPendingChunks(string $contentSourceId): void
    {
        $key = $this->getPendingChunksKey($contentSourceId);
        Redis::incr($key);

        Log::debug('Incremented pending chunks', [
            'content_source_id' => $contentSourceId,
            'count' => Redis::get($key),
        ]);
    }

    /**
     * Decrement pending chunks counter in Redis
     *
     * @return int The new count after decrement
     */
    public function decrementPendingChunks(string $contentSourceId): int
    {
        $key = $this->getPendingChunksKey($contentSourceId);
        $newCount = Redis::decr($key);

        // Ensure we don't go below 0
        if ($newCount < 0) {
            Redis::set($key, 0);
            $newCount = 0;
        }

        return (int) $newCount;
    }

    /**
     * Set scraping done flag in Redis
     */
    public function setScrapingDone(string $contentSourceId): void
    {
        $key = $this->getScrapingDoneKey($contentSourceId);
        Redis::set($key, '1');

        Log::debug('Set scraping done flag', [
            'content_source_id' => $contentSourceId,
        ]);
    }

    /**
     * Check if scraping is done
     */
    public function isScrapingDone(string $contentSourceId): bool
    {
        $key = $this->getScrapingDoneKey($contentSourceId);

        return (bool) Redis::get($key);
    }

    /**
     * Get pending chunks count from Redis
     */
    public function getPendingChunksCount(string $contentSourceId): int
    {
        $key = $this->getPendingChunksKey($contentSourceId);
        $count = Redis::get($key);

        return $count ? (int) $count : 0;
    }

    /**
     * Clean up Redis keys for a content source
     */
    public function cleanupRedisKeys(string $contentSourceId): void
    {
        $pendingKey = $this->getPendingChunksKey($contentSourceId);
        $doneKey = $this->getScrapingDoneKey($contentSourceId);

        Redis::del([$pendingKey, $doneKey]);

        Log::debug('Cleaned up Redis keys', [
            'content_source_id' => $contentSourceId,
        ]);
    }

    private function getPendingChunksKey(string $contentSourceId): string
    {
        return sprintf(self::REDIS_KEY_PENDING_CHUNKS, $contentSourceId);
    }

    private function getScrapingDoneKey(string $contentSourceId): string
    {
        return sprintf(self::REDIS_KEY_SCRAPING_DONE, $contentSourceId);
    }

    private function processPost(ContentSource $source, array $post): ?OriginalItem
    {
        $postId = $post['id'] ?? null;
        $postUrl = $post['url'] ?? null;
        $postText = $post['text'] ?? '';
        $postDate = $post['date'] ?? null;

        if (! $postId || ! $postUrl) {
            Log::warning('Skipping post with missing ID or URL', [
                'content_source_id' => $source->id,
                'post' => $post,
            ]);

            return null;
        }

        // Check if already exists
        $existing = $source->originalItems()
            ->where('source_url', $postUrl)
            ->first();

        if ($existing) {
            Log::debug('Post already exists, skipping', [
                'content_source_id' => $source->id,
                'post_url' => $postUrl,
            ]);

            return $existing;
        }

        // Generate title from text (first N words)
        $title = $this->generateTitle($postText, $postId);

        // Count words
        // $wordCount = preg_match_all('/[\p{L}\p{N}]+/u', $postText);
        $wordCount = (new WordCounter)->count($postText);

        // Prepare metadata
        $metadata = [
            'views' => $post['views'] ?? null,
            'type' => $post['type'] ?? null,
            'reactions' => $post['reactions'] ?? null,
        ];

        // Create OriginalItem
        $originalItem = $source->originalItems()->create([
            'title' => $title,
            'full_text' => $postText,
            'source_url' => $postUrl,
            'published_at' => $postDate ? new \DateTimeImmutable($postDate) : null,
            'word_count' => $wordCount ?: 0,
            'metadata' => $metadata,
        ]);

        // Log::debug('Created OriginalItem from Telegram post', [
        //     'content_source_id' => $source->id,
        //     'original_item_id' => $originalItem->id,
        //     'post_id' => $postId,
        // ]);

        return $originalItem;
    }

    private function generateTitle(string $text, int $postId): string
    {
        // Take first 8 words as title
        $words = preg_split('/\s+/', trim($text), 9);
        $titleWords = array_slice($words, 0, 8);
        $title = implode(' ', $titleWords);

        if (empty($title)) {
            $title = 'Post #'.$postId;
        }

        return $title;
    }
}
