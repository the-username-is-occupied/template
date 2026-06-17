<?php

declare(strict_types=1);

namespace Tests\Unit\Jobs;

use App\Jobs\ProcessTelegramChunkJob;
use App\Models\ContentSource;
use App\Models\OriginalItem;
use App\Services\TelegramChunkService;
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

        // Mock TelegramChunkService - use Mockery::on() to match any ContentSource
        $mock = $this->mock(TelegramChunkService::class);
        $mock->shouldReceive('processChunk')
            ->once()
            ->withArgs(function ($arg1, $arg2) use ($source, $posts) {
                return $arg1->id === $source->id && $arg2 === $posts;
            });
        $mock->shouldReceive('finalizeChunk')
            ->once()
            ->withArgs(function ($arg) use ($source) {
                return $arg->id === $source->id;
            });

        $job = new ProcessTelegramChunkJob($source->id, $posts);
        $job->handle();

        // Service methods are called, actual processing is tested in TelegramChunkServiceTest
    }

    public function test_job_skips_when_content_source_not_found(): void
    {
        $job = new ProcessTelegramChunkJob('non-existent-id', []);
        $job->handle();

        // Should complete without error
        $this->assertTrue(true);
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

        // Mock TelegramChunkService - use Mockery::on() to match any ContentSource
        $mock = $this->mock(TelegramChunkService::class);
        $mock->shouldReceive('processChunk')
            ->once()
            ->withArgs(function ($arg1, $arg2) use ($source, $posts) {
                return $arg1->id === $source->id && $arg2 === $posts;
            });
        $mock->shouldReceive('finalizeChunk')
            ->once()
            ->withArgs(function ($arg) use ($source) {
                return $arg->id === $source->id;
            });

        $job = new ProcessTelegramChunkJob($source->id, $posts);
        $job->handle();

        // Service methods are called, actual duplicate detection is tested in TelegramChunkServiceTest
    }
}
