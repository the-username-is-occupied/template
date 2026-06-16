<?php

declare(strict_types=1);

namespace Tests\Unit\Jobs;

use App\Jobs\ProcessTelegramChunkJob;
use App\Models\ContentSource;
use App\Models\OriginalItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ProcessTelegramChunkJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_job_processes_posts_and_creates_original_items(): void
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

        $job = new ProcessTelegramChunkJob($source->id, $posts);
        $job->handle();

        $this->assertDatabaseHas('original_items', [
            'content_source_id' => $source->id,
            'source_url' => 'https://t.me/test/123',
        ]);

        $originalItem = OriginalItem::where('content_source_id', $source->id)->first();
        $this->assertNotNull($originalItem->word_count);
        $this->assertEquals('1000', $originalItem->metadata['views']);
    }

    public function test_job_updates_last_fetched_id(): void
    {
        $source = ContentSource::factory()->uploading()->create(['last_fetched_id' => 100]);

        $posts = [
            ['id' => 150, 'url' => 'https://t.me/test/150', 'text' => 'Post 150'],
            ['id' => 200, 'url' => 'https://t.me/test/200', 'text' => 'Post 200'],
        ];

        $job = new ProcessTelegramChunkJob($source->id, $posts);
        $job->handle();

        $this->assertEquals(200, $source->fresh()->last_fetched_id);
    }

    public function test_job_skips_existing_posts(): void
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

        $job = new ProcessTelegramChunkJob($source->id, $posts);
        $job->handle();

        // Should still have only 1 original item
        $this->assertEquals(1, OriginalItem::where('content_source_id', $source->id)->count());
    }
}
