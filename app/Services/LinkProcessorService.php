<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\DiscoveryMethod;
use App\Enums\ExtractionStatus;
use App\Enums\ReviewStatus;
use App\Enums\SourceType;
use App\Exceptions\SourceDetectionException;
use App\Models\ContentSource;
use App\Models\OriginalItem;
use Illuminate\Support\Facades\Log;

class LinkProcessorService
{
    /**
     * Process links extracted from a Telegram post.
     *
     * @param  array<int, string>  $links
     * @return array<int, ContentSource> Created or existing content sources
     */
    public function processLinks(ContentSource $parentSource, OriginalItem $parentItem, array $links): array
    {
        $createdSources = [];

        foreach ($links as $link) {
            if (! is_string($link) || empty($link)) {
                continue;
            }

            // Filter out nested TG channels (channels without post ID)
            if ($this->isNestedTelegramChannel($link)) {
                Log::debug('Filtering out nested Telegram channel', [
                    'link' => $link,
                    'parent_source_id' => $parentSource->id,
                ]);

                continue;
            }

            // Deduplication: check if URL already exists
            $existing = ContentSource::where('url', $link)->first();

            if ($existing) {
                Log::debug('ContentSource already exists for link', [
                    'link' => $link,
                    'existing_id' => $existing->id,
                ]);

                continue;
            }

            // Detect source type
            $detectedType = $this->detectSourceType($link);

            // Create new ContentSource
            $newSource = ContentSource::create([
                'user_id' => $parentSource->user_id,
                'url' => $link,
                'type' => $detectedType,
                'discovery_method' => DiscoveryMethod::AutoExtracted,
                'review_status' => ReviewStatus::PendingReview,
                'parent_source_id' => $parentSource->id,
                'parent_item_id' => $parentItem->id,
                'extraction_status' => ExtractionStatus::Pending,
                'metadata' => [],
            ]);

            $createdSources[] = $newSource;

            Log::info('Created ContentSource from extracted link', [
                'new_source_id' => $newSource->id,
                'link' => $link,
                'type' => $detectedType->value,
                'parent_source_id' => $parentSource->id,
            ]);
        }

        return $createdSources;
    }

    /**
     * Check if a link is a Telegram link that should be skipped.
     */
    private function isNestedTelegramChannel(string $link): bool
    {
        return preg_match('#^(?:https?://)?(?:t\.me|telegram\.me)(?:/s)?/.*$#i', $link) === 1;
    }

    /**
     * Detect the source type from URL.
     */
    private function detectSourceType(string $url): SourceType
    {
        try {
            $detected = app(SmartUrlDetector::class)->detect($url);

            return $detected->type;
        } catch (\Throwable $e) {
            Log::warning('Failed to detect source type', [
                'url' => $url,
                'error' => $e->getMessage(),
            ]);

            throw new SourceDetectionException('Failed to detect source type for URL: '.$url.'. Error: '.$e->getMessage(), 0, $e);
        }
    }
}
