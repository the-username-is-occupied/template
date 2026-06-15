<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Events\TelegramLinksDiscovered;
use App\Events\TelegramParsingProgress;
use App\Models\ContentSource;
use App\Models\OriginalItem;
use App\Services\LinkProcessorService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class ProcessTelegramChunkJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $uniqueFor = 300; // 5 minutes

    public function __construct(
        public string $contentSourceId,
        public array $posts,
    ) {}

    public function uniqueId(): string
    {
        return 'process_telegram_chunk_'.$this->contentSourceId;
    }

    public function handle(): void
    {
        $source = ContentSource::find($this->contentSourceId);

        if (! $source) {
            Log::error('ContentSource not found for chunk processing', [
                'content_source_id' => $this->contentSourceId,
            ]);

            return;
        }

        if ($source->extraction_status->value !== 'uploading') {
            Log::warning('ContentSource is not in uploading status, skipping chunk', [
                'content_source_id' => $this->contentSourceId,
                'status' => $source->extraction_status->value,
            ]);

            return;
        }

        $processedCount = 0;
        $linksDiscovered = [];
        $maxPostId = $source->last_fetched_id ?? 0;

        foreach ($this->posts as $post) {
            $originalItem = $this->processPost($source, $post);

            if ($originalItem) {
                $processedCount++;

                // Update max post ID
                $postId = $post['id'] ?? 0;
                if ($postId > $maxPostId) {
                    $maxPostId = $postId;
                }

                // Process links from this post
                $links = $post['links'] ?? [];
                if (! empty($links)) {
                    $processedLinks = app(LinkProcessorService::class)
                        ->processLinks($source, $originalItem, $links);

                    $linksDiscovered = array_merge($linksDiscovered, $processedLinks);
                }
            }
        }

        // Update last_fetched_id
        if ($maxPostId > 0) {
            $source->update(['last_fetched_id' => $maxPostId]);
        }

        Log::info('Processed Telegram chunk', [
            'content_source_id' => $this->contentSourceId,
            'posts_processed' => $processedCount,
            'links_discovered' => count($linksDiscovered),
            'max_post_id' => $maxPostId,
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
        $wordCount = preg_match_all('/[\p{L}\p{N}]+/u', $postText);

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

        Log::debug('Created OriginalItem from Telegram post', [
            'content_source_id' => $source->id,
            'original_item_id' => $originalItem->id,
            'post_id' => $postId,
        ]);

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
