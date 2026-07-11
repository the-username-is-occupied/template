<?php

declare(strict_types=1);

use App\Domain\NotebookLM\DTOs\SourceDTO;
use App\Domain\NotebookLM\DTOs\SourceFulltextDTO;
use App\Domain\NotebookLM\NotebookLMService;
use App\Enums\ExtractionStatus;
use App\Models\ContentSource;
use App\Models\OriginalItem;
use App\Models\TechAccount;
use App\Models\TechNotebook;
use App\Models\User;
use App\Services\AccountService;
use App\Services\Extractors\YouTubeExtractor;
use App\Services\WordCounter;
use App\Services\YouTubeOriginalItemMetadataResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

test('YouTube channel extraction with 3 videos in 2 batches', function () {
    // Create user and tech account first
    $user = User::factory()->create();
    $techAccount = TechAccount::create([
        'id' => '019ecba7-ec15-7088-84c4-f167fc886ff4',
        'name' => 'Test Account',
        'email' => 'test@example.com',
        'pool_type' => 'free',
        'status' => 'active',
    ]);

    $notebook = TechNotebook::create([
        'account_id' => $techAccount->id,
        'notebook_id' => 'test-notebook-id',
        'type' => 'source_extractor',
        'sources_count' => 0,
        'max_sources' => 2, // Small batch size for testing
        'status' => 'idle',
    ]);

    // Create content source with video URLs
    $source = ContentSource::factory()
        ->for($user)
        ->youtube()
        ->withUrl('https://youtube.com/@testchannel')
        ->pending()
        ->state(fn () => [
            'metadata' => [
                'channel_meta' => [
                    'video_urls' => [
                        'https://youtube.com/watch?v=video1',
                        'https://youtube.com/watch?v=video2',
                        'https://youtube.com/watch?v=video3',
                    ],
                ],
            ],
        ])
        ->create(['title' => 'Test Channel']);

    dump($source->metadata);

    // Mock NotebookLMService
    $notebookLMService = Mockery::mock(NotebookLMService::class);

    // First batch (2 videos)
    $notebookLMService->shouldReceive('addSourceUrlsPool')
        ->once()
        ->andReturnUsing(function ($accountId, $notebookId, $urls, $concurrency) {
            dump('addSourceUrlsPool first batch', $urls);

            return [
                new SourceDTO(id: 'source-1', title: 'Video 1', url: null, created_at: now()->toISOString(), status: 'ready', kind: 'video'),
                new SourceDTO(id: 'source-2', title: 'Video 2', url: null, created_at: now()->toISOString(), status: 'ready', kind: 'video'),
            ];
        });

    $notebookLMService->shouldReceive('waitForSources')
        ->once()
        ->andReturn([]);

    $notebookLMService->shouldReceive('getSourceFulltextsPool')
        ->once()
        ->andReturnUsing(function ($accountId, $notebookId, $sourceIds, $concurrency) {
            dump('getSourceFulltextsPool first batch', $sourceIds);

            return [
                'source-1' => new SourceFulltextDTO(source_id: 'source-1', title: 'Video 1', content: 'Transcript 1', url: null, char_count: 100),
                'source-2' => new SourceFulltextDTO(source_id: 'source-2', title: 'Video 2', content: 'Transcript 2', url: null, char_count: 150),
            ];
        });

    $notebookLMService->shouldReceive('deleteSourcesPool')
        ->once();

    // Second batch (1 video)
    $notebookLMService->shouldReceive('addSourceUrlsPool')
        ->once()
        ->andReturnUsing(function ($accountId, $notebookId, $urls, $concurrency) {
            dump('addSourceUrlsPool second batch', $urls);

            return [
                new SourceDTO(id: 'source-3', title: 'Video 3', url: null, created_at: now()->toISOString(), status: 'ready', kind: 'video'),
            ];
        });

    $notebookLMService->shouldReceive('waitForSources')
        ->once()
        ->andReturn([]);

    $notebookLMService->shouldReceive('getSourceFulltextsPool')
        ->once()
        ->andReturnUsing(function ($accountId, $notebookId, $sourceIds, $concurrency) {
            dump('getSourceFulltextsPool second batch', $sourceIds);

            return [
                'source-3' => new SourceFulltextDTO(source_id: 'source-3', title: 'Video 3', content: 'Transcript 3', url: null, char_count: 120),
            ];
        });

    $notebookLMService->shouldReceive('deleteSourcesPool')
        ->once();

    // Create mocks for services
    $accountService = Mockery::mock(AccountService::class);
    $accountService->shouldReceive('getAvailableTechNotebook')
        ->andReturn($notebook);
    $accountService->shouldReceive('acquireTechNotebookLock')
        ->andReturn(true);
    $accountService->shouldReceive('releaseTechNotebookLock')
        ->andReturn(null);
    $accountService->shouldReceive('incrementSourcesCount')
        ->andReturn(null);
    $accountService->shouldReceive('decrementSourcesCount')
        ->andReturn(null);

    $wordCounter = Mockery::mock(WordCounter::class);
    $wordCounter->shouldReceive('count')
        ->times(3)
        ->andReturnUsing(fn (string $content) => str_word_count($content));

    $metadataResolver = Mockery::mock(YouTubeOriginalItemMetadataResolver::class);
    $metadataResolver->shouldReceive('resolveAndUpdate')
        ->once()
        ->with(
            Mockery::on(fn (Collection $items) => $items->pluck('source_url')->all() === [
                'https://youtube.com/watch?v=video1',
                'https://youtube.com/watch?v=video2',
                'https://youtube.com/watch?v=video3',
            ])
        )
        ->andReturnUsing(fn (Collection $items) => $items);

    $extractor = new YouTubeExtractor($accountService, $notebookLMService, $wordCounter, $metadataResolver);
    $extractor->extract($source);

    // Refresh source from database
    $source->refresh();
    dump('error_message', $source->error_message);

    // Assert extraction status
    expect($source->extraction_status)->toBe(ExtractionStatus::Extracted);

    // Assert OriginalItems were created
    $originalItems = OriginalItem::where('content_source_id', $source->id)->get();
    dump($originalItems->pluck('source_url')->all());
    expect($originalItems)->toHaveCount(3);
    expect($originalItems[0]->title)->toBe('Video 1');
    expect($originalItems[0]->full_text)->toBe('Transcript 1');
    expect($originalItems[1]->title)->toBe('Video 2');
    expect($originalItems[2]->title)->toBe('Video 3');
});
