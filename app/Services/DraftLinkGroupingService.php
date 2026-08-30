<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\ReviewStatus;
use App\Enums\SourceType;
use App\Models\ContentSource;
use App\Models\SourceDraft;

class DraftLinkGroupingService
{
    /**
     * Get discovered links grouped by type/domain/channel.
     *
     * @return array<int, array{domain: string, type: string, channel: string|null, count: int, items: array}>
     */
    public function getGroupedLinks(SourceDraft $draft): array
    {
        // Get pending_review sources that are children of this draft's content source
        $contentSource = $draft->contentSource;

        if (! $contentSource) {
            return [];
        }

        $pendingSources = ContentSource::where('parent_source_id', $contentSource->id)
            ->where('review_status', ReviewStatus::PendingReview)
            ->with('parentItem')
            ->get();

        // Group by type and domain/channel
        $grouped = [];

        foreach ($pendingSources as $source) {
            $domain = $this->extractDomain($source->url);
            $channel = $this->extractChannel($source->url, $source->type);

            $key = $source->type->value.'_'.($channel ?: $domain);

            if (! isset($grouped[$key])) {
                $grouped[$key] = [
                    'domain' => $domain,
                    'type' => $source->type->value,
                    'channel' => $channel,
                    'count' => 0,
                    'items' => [],
                ];
            }

            $grouped[$key]['count']++;
            $grouped[$key]['items'][] = [
                'id' => $source->id,
                'url' => $source->url,
                'source_post_url' => $source->parentItem?->source_url,
                'review_status' => $source->review_status->value,
            ];
        }

        return array_values($grouped);
    }

    private function extractDomain(string $url): string
    {
        $host = parse_url($url, PHP_URL_HOST);

        return $host ?: $url;
    }

    private function extractChannel(string $url, SourceType $type): ?string
    {
        if ($type !== SourceType::TelegramChannel) {
            return null;
        }

        if (preg_match('#t\.me/(?:s/)?([^/?]+)#', $url, $matches)) {
            return '@'.$matches[1];
        }

        return null;
    }
}
