<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Enums\DiscoveryMethod;
use App\Enums\ExtractionStatus;
use App\Enums\ReviewStatus;
use App\Models\ContentSource;
use App\Models\OriginalItem;
use App\Services\LinkProcessorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class LinkProcessorServiceTest extends TestCase
{
    use RefreshDatabase;

    private LinkProcessorService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new LinkProcessorService;
    }

    public function test_process_links_filters_all_telegram_links(): void
    {
        $parentSource = ContentSource::factory()->create();
        $parentItem = OriginalItem::factory()->create(['content_source_id' => $parentSource->id]);

        $links = [
            'https://t.me/channel_without_post',
            'https://t.me/channel/abc',
            'https://t.me/channel/123',
            'https://telegram.me/channel/123',
        ];

        $result = $this->service->processLinks($parentSource, $parentItem, $links);

        $this->assertCount(0, $result);
    }

    public function test_process_links_deduplicates(): void
    {
        $parentSource = ContentSource::factory()->create();
        $parentItem = OriginalItem::factory()->create(['content_source_id' => $parentSource->id]);

        // Create existing source
        ContentSource::factory()->create([
            'url' => 'https://t.me/existing/123',
        ]);

        $links = [
            'https://t.me/existing/123',  // Should be deduplicated
            'https://example.com/new/456',  // Should be created
        ];

        $result = $this->service->processLinks($parentSource, $parentItem, $links);

        $this->assertCount(1, $result);
        $this->assertEquals('https://example.com/new/456', $result[0]->url);
    }

    public function test_process_links_creates_correct_source(): void
    {
        $parentSource = ContentSource::factory()->create();
        $parentItem = OriginalItem::factory()->create(['content_source_id' => $parentSource->id]);

        $links = [
            'https://example.com/test_channel/123',
        ];

        $result = $this->service->processLinks($parentSource, $parentItem, $links);

        $this->assertCount(1, $result);
        $source = $result[0];

        $this->assertEquals($parentSource->user_id, $source->user_id);
        $this->assertEquals('https://example.com/test_channel/123', $source->url);
        $this->assertEquals(DiscoveryMethod::AutoExtracted, $source->discovery_method);
        $this->assertEquals(ReviewStatus::PendingReview, $source->review_status);
        $this->assertEquals($parentSource->id, $source->parent_source_id);
        $this->assertEquals($parentItem->id, $source->parent_item_id);
        $this->assertEquals(ExtractionStatus::Pending, $source->extraction_status);
    }

    public function test_process_links_is_idempotent(): void
    {
        $parentSource = ContentSource::factory()->create();
        $parentItem = OriginalItem::factory()->create(['content_source_id' => $parentSource->id]);

        $links = [
            'https://example.com/test/123',
        ];

        // First call
        $result1 = $this->service->processLinks($parentSource, $parentItem, $links);
        $this->assertCount(1, $result1);

        // Second call with same links
        $result2 = $this->service->processLinks($parentSource, $parentItem, $links);
        $this->assertCount(0, $result2);  // No new sources created

        // Verify only one source exists
        $this->assertEquals(1, ContentSource::where('parent_source_id', $parentSource->id)->count());
    }
}
