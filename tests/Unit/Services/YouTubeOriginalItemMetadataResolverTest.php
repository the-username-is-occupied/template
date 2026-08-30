<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Domain\YouTube\DTOs\ChannelUrlMappingData;
use App\Models\OriginalItem;
use App\Services\YouTubeOriginalItemMetadataResolver;
use App\Services\YouTubeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\LaravelData\DataCollection;
use Tests\TestCase;

final class YouTubeOriginalItemMetadataResolverTest extends TestCase
{
    use RefreshDatabase;

    public function test_resolve_and_update_updates_original_item_metadata(): void
    {
        $items = OriginalItem::factory()->count(2)->create([
            'metadata' => [],
        ]);

        $this->mock(YouTubeService::class, function ($mock) use ($items): void {
            $mock->shouldReceive('resolveChannelUrls')
                ->once()
                ->with($this->callback(function (array $urls) use ($items): bool {
                    $this->assertSame([$items[0]->source_url, $items[1]->source_url], $urls);

                    return true;
                }), true)
                ->andReturn(ChannelUrlMappingData::collect([
                    new ChannelUrlMappingData(
                        url: $items[0]->source_url,
                        handle: '@test1',
                        videoId: 'abc123def45',
                        title: 'Video 1',
                        description: 'Desc 1',
                        tags: ['tag1'],
                        channelId: 'UC123',
                        channelTitle: 'Channel 1',
                        publishedAt: '2026-01-01T00:00:00Z',
                        duration: 'PT2M30S',
                        viewCount: 100,
                        likeCount: 10,
                        commentCount: 1,
                    ),
                    new ChannelUrlMappingData(
                        url: $items[1]->source_url,
                        handle: '@test2',
                        videoId: 'zxy987wvu65',
                        title: 'Video 2',
                        description: 'Desc 2',
                        tags: ['tag2'],
                        channelId: 'UC456',
                        channelTitle: 'Channel 2',
                        publishedAt: '2026-02-01T00:00:00Z',
                        duration: 'PT1M15S',
                        viewCount: 200,
                        likeCount: 20,
                        commentCount: 2,
                    ),
                ], DataCollection::class));
        });

        $resolver = new YouTubeOriginalItemMetadataResolver(app(YouTubeService::class));
        $resolver->resolveAndUpdate($items);

        foreach ($items as $item) {
            $item->refresh();
            $this->assertArrayHasKey('youtube', $item->metadata);
            $this->assertSame($item->source_url, $item->metadata['youtube']['url']);
        }

        $this->assertSame('@test1', $items[0]->fresh()->metadata['youtube']['handle']);
        $this->assertSame('@test2', $items[1]->fresh()->metadata['youtube']['handle']);
    }

    public function test_resolve_and_update_skips_items_without_source_url(): void
    {
        $item = OriginalItem::factory()->create([
            'source_url' => null,
            'metadata' => ['existing' => 'value'],
        ]);

        $this->mock(YouTubeService::class, function ($mock): void {
            $mock->shouldNotReceive('resolveChannelUrls');
        });

        $resolver = new YouTubeOriginalItemMetadataResolver(app(YouTubeService::class));
        $resolver->resolveAndUpdate(collect([$item]));

        $this->assertSame(['existing' => 'value'], $item->fresh()->metadata);
    }
}
