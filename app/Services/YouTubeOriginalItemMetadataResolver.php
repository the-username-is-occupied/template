<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\OriginalItem;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class YouTubeOriginalItemMetadataResolver
{
    public function __construct(private readonly YouTubeService $youTubeService) {}

    /**
     * Resolve YouTube metadata for the provided OriginalItems and persist it into metadata.
     *
     * @param  Collection<int, OriginalItem>  $originalItems
     * @return Collection<int, OriginalItem>
     */
    public function resolveAndUpdate(Collection $originalItems): Collection
    {
        $originalItems = $originalItems->values();
        $urls = $originalItems->pluck('source_url')
            ->filter()
            ->unique()
            ->values()
            ->all();

        if (empty($urls)) {
            return $originalItems;
        }

        $mappings = $this->youTubeService->resolveChannelUrls($urls, true);
        $mappingsByUrl = [];

        foreach ($mappings as $mapping) {
            $mappingsByUrl[$mapping->url] = $mapping;
        }

        foreach ($originalItems as $item) {
            if (! $item->source_url) {
                continue;
            }

            $mapping = $mappingsByUrl[$item->source_url] ?? null;

            if (! $mapping) {
                continue;
            }

            $metadata = $item->metadata ?? [];
            $metadata['youtube'] = $mapping->toArray();
            $item->published_at = Carbon::parse($mapping->publishedAt) ?? null;
            $item->metadata = $metadata;
            $item->save();
        }

        return $originalItems;
    }
}
