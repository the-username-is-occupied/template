<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Domain\Telegram\TGScraperService;
use App\Enums\ExtractionStatus;
use App\Models\ContentSource;
use App\Services\Extractors\TelegramExtractor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class TelegramExtractorTest extends TestCase
{
    use RefreshDatabase;

    public function test_extract_sets_status_to_uploading(): void
    {
        $source = ContentSource::factory()
            ->withTelegramUrl('test_channel')
            ->pending()
            ->create();

        $mockScraper = $this->mock(TGScraperService::class);
        $mockScraper->shouldReceive('scrape')->once();

        $extractor = new TelegramExtractor($mockScraper);
        $extractor->extract($source);

        $this->assertEquals(ExtractionStatus::Uploading, $source->fresh()->extraction_status);
    }

    public function test_extract_calls_scraper_with_correct_parameters(): void
    {
        $source = ContentSource::factory()
            ->withTelegramUrl('test_channel')
            ->withScrapeConfig([
                'limit' => 100,
                'from_id' => 1000,
                'workers' => 5,
            ])
            ->create();

        $mockScraper = $this->mock(TGScraperService::class);
        $mockScraper->shouldReceive('scrape')
            ->once()
            ->withArgs(function ($contentSourceId, $channel, $limit, $fromId, $toId, $fromDate, $toDate, $workers, $chunkLimit, $hookUrl): bool {
                return $channel === 'test_channel' &&
                       $limit === 100 &&
                       $fromId === 1000 &&
                       $workers === 5;
            });

        $extractor = new TelegramExtractor($mockScraper);
        $extractor->extract($source);
    }

    public function test_extract_handles_missing_channel(): void
    {
        $source = ContentSource::factory()
            ->withUrl('https://invalid-url.com')
            ->create();

        $mockScraper = $this->mock(TGScraperService::class);
        $mockScraper->shouldNotReceive('scrape');

        $extractor = new TelegramExtractor($mockScraper);
        $extractor->extract($source);

        $this->assertEquals(ExtractionStatus::Error, $source->fresh()->extraction_status);
        $this->assertEquals('invalid_channel', $source->fresh()->error_code);
    }
}
