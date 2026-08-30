<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Enums\ExtractionStatus;
use App\Enums\SourceDraftStatus;
use App\Events\TelegramParsingDone;
use App\Events\TelegramParsingProgress;
use App\Models\ContentSource;
use App\Models\OriginalItem;
use App\Models\SourceDraft;
use App\Services\TelegramChunkService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

final class TelegramChunkServiceTest extends TestCase
{
    use RefreshDatabase;

    private TelegramChunkService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new TelegramChunkService;
        Redis::flushall();
    }

    protected function tearDown(): void
    {
        Redis::flushall();
        parent::tearDown();
    }

    public function test_process_chunk_creates_original_items(): void
    {
        $source = ContentSource::factory()->uploading()->create();

        $posts = [
            [
                'id' => 123,
                'url' => 'https://t.me/test/123',
                'text' => 'This is a test post with some text',
                'date' => '2024-01-01T00:00:00Z',
                'views' => '1000',
                'type' => 'message',
                'reactions' => ['👍' => 10],
                'links' => [],
            ],
        ];

        $result = $this->service->processChunk($source, $posts);

        $this->assertEquals(1, $result['processedCount']);
        $this->assertEmpty($result['linksDiscovered']);

        $this->assertDatabaseHas('original_items', [
            'content_source_id' => $source->id,
            'source_url' => 'https://t.me/test/123',
        ]);

        $originalItem = OriginalItem::where('content_source_id', $source->id)->first();
        $this->assertNotNull($originalItem->word_count);
        $this->assertEquals('1000', $originalItem->metadata['views']);
    }

    public function test_process_chunk_skips_existing_posts(): void
    {
        $source = ContentSource::factory()->uploading()->create();

        // Create existing post
        OriginalItem::factory()->create([
            'content_source_id' => $source->id,
            'source_url' => 'https://t.me/test/123',
        ]);

        $posts = [
            [
                'id' => 123,
                'url' => 'https://t.me/test/123',
                'text' => 'Updated text',
            ],
        ];

        $result = $this->service->processChunk($source, $posts);

        $this->assertEquals(1, $result['processedCount']); // Returns existing item
        $this->assertEquals(1, OriginalItem::where('content_source_id', $source->id)->count());
    }

    public function test_process_chunk_skips_non_uploading_source(): void
    {
        $source = ContentSource::factory()->create(['extraction_status' => ExtractionStatus::Extracted]);

        $posts = [
            ['id' => 123, 'url' => 'https://t.me/test/123', 'text' => 'Test'],
        ];

        $result = $this->service->processChunk($source, $posts);

        $this->assertEquals(0, $result['processedCount']);
        $this->assertDatabaseMissing('original_items', [
            'content_source_id' => $source->id,
        ]);
    }

    public function test_finalize_chunk_executes_done_logic_when_done_and_no_pending(): void
    {
        $source = ContentSource::factory()->uploading()->create();
        $draft = SourceDraft::factory()->create([
            'content_source_id' => $source->id,
            'status' => 'fetching_meta',
        ]);

        // Set scraping done flag
        $this->service->setScrapingDone($source->id);

        // No pending chunks (counter is 0 by default)
        $this->service->finalizeChunk($source);

        // Check that done logic was executed
        $source->refresh();
        $this->assertEquals(ExtractionStatus::Extracted, $source->extraction_status);

        $draft->refresh();
        $this->assertEquals(SourceDraftStatus::AwaitingIndex, $draft->status);
    }

    public function test_finalize_chunk_does_not_execute_done_logic_when_pending_chunks(): void
    {
        $source = ContentSource::factory()->uploading()->create();

        // Set scraping done flag
        $this->service->setScrapingDone($source->id);

        // Add pending chunks
        $this->service->incrementPendingChunks($source->id);
        $this->service->incrementPendingChunks($source->id);

        // Finalize one chunk (should decrement to 1, not execute done logic)
        $this->service->finalizeChunk($source);

        $source->refresh();
        $this->assertEquals(ExtractionStatus::Uploading, $source->extraction_status);
    }

    public function test_finalize_chunk_executes_done_logic_when_last_chunk(): void
    {
        $source = ContentSource::factory()->uploading()->create();
        $draft = SourceDraft::factory()->create([
            'content_source_id' => $source->id,
            'status' => 'fetching_meta',
        ]);

        // Set scraping done flag
        $this->service->setScrapingDone($source->id);

        // Add one pending chunk
        $this->service->incrementPendingChunks($source->id);

        // Finalize the chunk (should decrement to 0 and execute done logic)
        $this->service->finalizeChunk($source);

        $source->refresh();
        $this->assertEquals(ExtractionStatus::Extracted, $source->extraction_status);

        $draft->refresh();
        $this->assertEquals(SourceDraftStatus::AwaitingIndex, $draft->status);
    }

    public function test_redis_operations(): void
    {
        $contentSourceId = 'test-source-id';

        // Test increment
        $this->service->incrementPendingChunks($contentSourceId);
        $this->assertSame(1, $this->service->getPendingChunksCount($contentSourceId));

        // Test decrement
        $this->service->decrementPendingChunks($contentSourceId);
        $this->assertSame(0, $this->service->getPendingChunksCount($contentSourceId));

        // Test set/get scraping done
        $this->service->setScrapingDone($contentSourceId);
        $this->assertTrue($this->service->isScrapingDone($contentSourceId));

        // Test cleanup
        $this->service->cleanupRedisKeys($contentSourceId);
        $this->assertSame(0, $this->service->getPendingChunksCount($contentSourceId));
        $this->assertFalse($this->service->isScrapingDone($contentSourceId));
    }

    public function test_decrement_pending_chunks_does_not_go_below_zero(): void
    {
        $contentSourceId = 'test-source-id';

        // Decrement without incrementing first
        $result = $this->service->decrementPendingChunks($contentSourceId);

        $this->assertSame(0, $result);
        $this->assertSame(0, $this->service->getPendingChunksCount($contentSourceId));
    }

    public function test_execute_done_logic_dispatches_sse_event(): void
    {
        Event::fake();

        $source = ContentSource::factory()->uploading()->create();
        SourceDraft::factory()->create([
            'content_source_id' => $source->id,
            'status' => 'fetching_meta',
        ]);

        $this->service->executeDoneLogic($source);

        Event::assertDispatched(TelegramParsingDone::class);
    }

    public function test_process_chunk_dispatches_sse_events(): void
    {
        Event::fake();

        $source = ContentSource::factory()->uploading()->create();
        SourceDraft::factory()->create([
            'content_source_id' => $source->id,
        ]);

        $posts = [
            [
                'id' => 123,
                'url' => 'https://t.me/test/123',
                'text' => 'Test post',
            ],
        ];

        $this->service->processChunk($source, $posts);

        Event::assertDispatched(TelegramParsingProgress::class);
    }

    public function test_cleanup_redis_keys_after_done_logic(): void
    {
        $source = ContentSource::factory()->uploading()->create();

        // Set Redis keys
        $this->service->incrementPendingChunks($source->id);
        $this->service->setScrapingDone($source->id);

        // Execute done logic
        $this->service->executeDoneLogic($source);

        // Keys should be cleaned up
        $this->assertSame(0, $this->service->getPendingChunksCount($source->id));
        $this->assertFalse($this->service->isScrapingDone($source->id));
    }
}
