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
use App\Services\Extractors\WebsiteExtractor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

test('WebsiteExtractor loads URL, extracts text, and deletes source in finally', function () {
    // Create user and tech account first
    $user = User::factory()->create();
    $techAccount = TechAccount::create([
        'name' => 'Test Account',
        'email' => 'test@example.com',
        'pool_type' => 'source_extractor',
        'status' => 'active',
    ]);

    // Create tech notebook
    $notebook = TechNotebook::create([
        'account_id' => $techAccount->id,
        'notebook_id' => 'test-notebook-id',
        'type' => 'source_extractor',
        'sources_count' => 0,
        'max_sources' => 20,
        'status' => 'idle',
    ]);

    // Create content source
    $source = ContentSource::create([
        'user_id' => $user->id,
        'type' => 'website',
        'source_url' => 'https://example.com',
        'title' => 'Test Website',
        'extraction_status' => 'pending',
    ]);

    // Mock NotebookLMService
    $notebookLMService = Mockery::mock(NotebookLMService::class);

    $notebookLMService->shouldReceive('addSourceUrl')
        ->once()
        ->with($techAccount->id, $notebook->notebook_id, 'https://example.com')
        ->andReturn(new SourceDTO(id: 'source-1', title: 'Example Domain', url: 'https://example.com', created_at: now()->toISOString(), status: 'ready', kind: 'website'));

    $notebookLMService->shouldReceive('waitUntilReady')
        ->once()
        ->andReturn(new SourceDTO(id: 'source-1', title: 'Example Domain', url: 'https://example.com', created_at: now()->toISOString(), status: 'ready', kind: 'website'));

    $notebookLMService->shouldReceive('getSourceFulltext')
        ->once()
        ->andReturn(new SourceFulltextDTO(source_id: 'source-1', title: 'Example Domain', content: 'This is example text content', url: 'https://example.com', char_count: 50));

    $notebookLMService->shouldReceive('deleteSource')
        ->once()
        ->with($techAccount->id, $notebook->notebook_id, 'source-1')
        ->andReturn(true);

    // Mock AccountService
    $accountService = Mockery::mock(AccountService::class);
    $accountService->shouldReceive('getAvailableTechNotebook')
        ->once()
        ->with('source_extractor')
        ->andReturn($notebook);
    $accountService->shouldReceive('acquireTechNotebookLock')
        ->once()
        ->andReturn(true);
    $accountService->shouldReceive('releaseTechNotebookLock')
        ->once()
        ->andReturn(null);
    $accountService->shouldReceive('incrementSourcesCount')
        ->once()
        ->andReturn(null);

    // Create extractor and run extraction
    $extractor = new WebsiteExtractor($accountService, $notebookLMService);
    $extractor->extract($source);

    // Refresh source from database
    $source->refresh();

    // Assert extraction status
    expect($source->extraction_status)->toBe(ExtractionStatus::Extracted);

    // Assert OriginalItem was created
    $originalItem = OriginalItem::where('content_source_id', $source->id)->first();
    expect($originalItem)->not->toBeNull();
    expect($originalItem->title)->toBe('Example Domain');
    expect($originalItem->full_text)->toBe('This is example text content');
    expect($originalItem->source_url)->toBe('https://example.com');
    expect($originalItem->word_count)->toBeGreaterThan(0);
});

test('WebsiteExtractor deletes source in finally block even on exception', function () {
    // Create user and tech account first
    $user = User::factory()->create();
    $techAccount = TechAccount::create([
        'name' => 'Test Account 2',
        'email' => 'test2@example.com',
        'pool_type' => 'source_extractor',
        'status' => 'active',
    ]);

    // Create tech notebook
    $notebook = TechNotebook::create([
        'account_id' => $techAccount->id,
        'notebook_id' => 'test-notebook-id-2',
        'type' => 'source_extractor',
        'sources_count' => 0,
        'max_sources' => 20,
        'status' => 'idle',
    ]);

    // Create content source
    $source = ContentSource::create([
        'user_id' => $user->id,
        'type' => 'website',
        'source_url' => 'https://example.com',
        'title' => 'Test Website',
        'extraction_status' => 'pending',
    ]);

    // Mock NotebookLMService to throw exception
    $notebookLMService = Mockery::mock(NotebookLMService::class);

    $notebookLMService->shouldReceive('addSourceUrl')
        ->once()
        ->with($techAccount->id, $notebook->notebook_id, 'https://example.com')
        ->andReturn(new SourceDTO(id: 'source-1', title: 'Test', url: null, created_at: now()->toISOString(), status: 'ready', kind: 'website'));

    $notebookLMService->shouldReceive('waitUntilReady')
        ->once()
        ->andThrow(new Exception('Indexing failed'));

    // deleteSource should still be called in finally block
    $notebookLMService->shouldReceive('deleteSource')
        ->once()
        ->with($techAccount->id, $notebook->notebook_id, 'source-1')
        ->andReturn(true);

    // Mock AccountService
    $accountService = Mockery::mock(AccountService::class);
    $accountService->shouldReceive('getAvailableTechNotebook')
        ->once()
        ->with('source_extractor')
        ->andReturn($notebook);
    $accountService->shouldReceive('acquireTechNotebookLock')
        ->once()
        ->andReturn(true);
    $accountService->shouldReceive('releaseTechNotebookLock')
        ->once()
        ->andReturn(null);

    // Create extractor and run extraction
    $extractor = new WebsiteExtractor($accountService, $notebookLMService);

    expect(fn () => $extractor->extract($source))->toThrow(Exception::class);

    // Refresh source from database
    $source->refresh();

    // Assert extraction status is error
    expect($source->extraction_status)->toBe(ExtractionStatus::Error);
    expect($source->error_message)->toBe('Indexing failed');
});
