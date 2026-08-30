<?php

declare(strict_types=1);

namespace App\Services\Extractors;

use App\Contracts\SourceExtractorInterface;
use App\Domain\Telegram\TGScraperService;
use App\Enums\ExtractionStatus;
use App\Models\ContentSource;
use DateTimeImmutable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Throwable;

class TelegramExtractor implements SourceExtractorInterface
{
    public function __construct(
        private readonly TGScraperService $tgScraperService,
    ) {}

    public function extract(ContentSource $source): void
    {
        // Set status to uploading
        $source->update([
            'extraction_status' => ExtractionStatus::Uploading,
            'error_message' => null,
            'error_code' => null,
        ]);

        // Get channel from source URL or metadata
        $channel = $this->extractChannelFromSource($source);

        if (! $channel) {
            $source->update([
                'extraction_status' => ExtractionStatus::Error,
                'error_message' => 'Could not determine Telegram channel from source.',
                'error_code' => 'invalid_channel',
            ]);

            return;
        }

        // Build webhook URL
        $hookUrl = $this->buildWebhookUrl();

        // Get scrape config from source metadata
        $scrapeConfig = $source->metadata['scrape_config'] ?? $source->sourceDrafts()->first()?->scrape_config ?? [];

        try {
            // Call TG Scraper service
            $this->tgScraperService->scrape(
                contentSourceId: $source->id,
                channel: $channel,
                limit: $scrapeConfig['limit'] ?? 0,
                fromId: $scrapeConfig['from_id'] ?? null,
                toId: $scrapeConfig['to_id'] ?? null,
                fromDate: isset($scrapeConfig['from_date']) ? new DateTimeImmutable($scrapeConfig['from_date']) : null,
                toDate: isset($scrapeConfig['to_date']) ? new DateTimeImmutable($scrapeConfig['to_date']) : null,
                workers: $scrapeConfig['workers'] ?? 3,
                chunkLimit: $scrapeConfig['chunk_limit'] ?? 2000,
                hookUrl: $hookUrl,
            );

            // Job returns immediately - parsing is async
            // Webhook will receive results via action=upload and action=done
            Log::info('Telegram scraping started', [
                'content_source_id' => $source->id,
                'channel' => $channel,
                'hook_url' => $hookUrl,
            ]);
        } catch (Throwable $e) {
            $source->update([
                'extraction_status' => ExtractionStatus::Error,
                'error_message' => 'Failed to start Telegram scraping: '.$e->getMessage(),
                'error_code' => 'scraper_error',
            ]);

            Log::error('Failed to start Telegram scraping', [
                'content_source_id' => $source->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function buildWebhookUrl(): string
    {
        // Build internal webhook URL for TG Scraper to call back
        return 'http://app:80/api/webhooks/telegram-scraper';
    }

    private function extractChannelFromSource(ContentSource $source): ?string
    {
        // Try to extract channel from URL
        $url = $source->url;

        if ($url && preg_match('#t\.me/(?:s/)?([^/?]+)#', $url, $matches)) {
            return $matches[1];
        }

        // Try from metadata
        if (isset($source->metadata['channel'])) {
            return $source->metadata['channel'];
        }

        return null;
    }
}
