<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\SourcePipeline\DTOs\SourceTypeData;
use App\Enums\SourceType;

class SmartUrlDetector
{
    /**
     * Detect the source type from a URL.
     */
    public function detect(string $url): SourceTypeData
    {
        $url = trim($url);

        // Telegram channel patterns
        if (preg_match('#t\.me/s/([a-zA-Z0-9_]+)#i', $url, $matches) ||
            preg_match('#t\.me/([a-zA-Z0-9_]+)#i', $url, $matches)) {
            return new SourceTypeData(
                type: SourceType::TelegramChannel,
                normalizedId: $matches[1]
            );
        }

        // YouTube channel patterns
        if (preg_match('#youtube\.com/@([a-zA-Z0-9_-]+)#i', $url, $matches) ||
            preg_match('#youtube\.com/channel/([a-zA-Z0-9_-]+)#i', $url, $matches) ||
            preg_match('#youtube\.com/c/([a-zA-Z0-9_-]+)#i', $url, $matches)) {
            $handle = $matches[1];

            // For channel URLs, normalizedId is the handle or ID
            return new SourceTypeData(
                type: SourceType::YoutubeChannel,
                normalizedId: $handle
            );
        }

        // YouTube playlist (treat as channel)
        if (preg_match('#youtube\.com/playlist\?list=([a-zA-Z0-9_-]+)#i', $url, $matches)) {
            return new SourceTypeData(
                type: SourceType::YoutubeChannel,
                normalizedId: $matches[1]
            );
        }

        // YouTube video (without &list=)
        if (preg_match('#youtube\.com/watch\?v=([a-zA-Z0-9_-]+)(?!.*&list=)#i', $url, $matches) ||
            preg_match('#youtu\.be/([a-zA-Z0-9_-]+)#i', $url, $matches)) {
            return new SourceTypeData(
                type: SourceType::YoutubeVideo,
                normalizedId: $matches[1]
            );
        }

        // Default: website
        return new SourceTypeData(
            type: SourceType::Website,
            normalizedId: $url
        );
    }

    /**
     * Parse multiple URLs from raw input (space or newline separated).
     *
     * @return array<int, string>
     */
    public function parseRawInput(string $rawInput): array
    {
        // Split by whitespace (spaces, newlines, tabs)
        $urls = preg_split('/\s+/', trim($rawInput), -1, PREG_SPLIT_NO_EMPTY);

        return array_values(array_filter($urls, fn ($url) => ! empty(trim($url))));
    }
}
