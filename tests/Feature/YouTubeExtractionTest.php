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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

test('YouTube channel extraction with 3 videos in 2 batches', function () {
    // Create user and tech account first
    $user = User::factory()->create();
    $techAccount = TechAccount::create([
        'id' => '019ecba7-ec15-7088-84c4-f167fc886ff4',
        'name' => 'Test Account',
        'email' => 'test@example.com',
        'pool_type' => 'source_extractor',
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
    $source = ContentSource::create([
        'user_id' => $user->id,
        'type' => 'youtube_channel',
        'url' => 'https://youtube.com/@testchannel',
        'title' => 'Test Channel',
        'extraction_status' => 'pending',
        'metadata' => [
            'video_urls' => [
                'https://youtube.com/watch?v=video1',
                'https://youtube.com/watch?v=video2',
                'https://youtube.com/watch?v=video3',
            ],
        ],
    ]);

    // Mock NotebookLMService
    $notebookLMService = Mockery::mock(NotebookLMService::class);

    // First batch (2 videos)
    $notebookLMService->shouldReceive('addSourceUrl')
        ->twice()
        ->andReturn(
            new SourceDTO(id: 'source-1', title: 'Video 1', url: null, created_at: now()->toISOString(), status: 'ready', kind: 'video'),
            new SourceDTO(id: 'source-2', title: 'Video 2', url: null, created_at: now()->toISOString(), status: 'ready', kind: 'video')
        );

    $notebookLMService->shouldReceive('waitForSources')
        ->once()
        ->andReturn([]);

    $notebookLMService->shouldReceive('getSourceFulltext')
        ->twice()
        ->andReturn(
            new SourceFulltextDTO(source_id: 'source-1', title: 'Video 1', content: 'Transcript 1', url: null, char_count: 100),
            new SourceFulltextDTO(source_id: 'source-2', title: 'Video 2', content: 'Transcript 2', url: null, char_count: 150)
        );

    $notebookLMService->shouldReceive('deleteSource')
        ->twice();

    // Second batch (1 video)
    $notebookLMService->shouldReceive('addSourceUrl')
        ->once()
        ->andReturn(new SourceDTO(id: 'source-3', title: 'Video 3', url: null, created_at: now()->toISOString(), status: 'ready', kind: 'video'));

    $notebookLMService->shouldReceive('waitForSources')
        ->once()
        ->andReturn([]);

    $notebookLMService->shouldReceive('getSourceFulltext')
        ->once()
        ->andReturn(new SourceFulltextDTO(source_id: 'source-3', title: 'Video 3', content: 'Transcript 3', url: null, char_count: 120));

    $notebookLMService->shouldReceive('deleteSource')
        ->once();

    // Mock AccountService
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

    // Create extractor and run extraction
    $extractor = new YouTubeExtractor($accountService, $notebookLMService);
    $extractor->extract($source);

    // Refresh source from database
    $source->refresh();

    // Assert extraction status
    expect($source->extraction_status)->toBe(ExtractionStatus::Extracted);

    // Assert OriginalItems were created
    $originalItems = OriginalItem::where('content_source_id', $source->id)->get();
    expect($originalItems)->toHaveCount(3);
    expect($originalItems[0]->title)->toBe('Video 1');
    expect($originalItems[0]->full_text)->toBe('Transcript 1');
    expect($originalItems[1]->title)->toBe('Video 2');
    expect($originalItems[2]->title)->toBe('Video 3');
});
