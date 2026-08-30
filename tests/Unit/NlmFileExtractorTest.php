<?php

declare(strict_types=1);

use App\Domain\NotebookLM\DTOs\SourceDTO;
use App\Domain\NotebookLM\DTOs\SourceFulltextDTO;
use App\Domain\NotebookLM\NotebookLMService;
use App\Enums\ExtractionStatus;
use App\Enums\SourceType;
use App\Models\ContentSource;
use App\Models\OriginalItem;
use App\Models\TechAccount;
use App\Models\TechNotebook;
use App\Models\User;
use App\Services\AccountService;
use App\Services\Extractors\NlmFileExtractor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

test('NlmFileExtractor uploads file, extracts text, and deletes file in finally', function (): void {
    // Create user and tech account first
    $user = User::factory()->create();
    $techAccount = TechAccount::create([
        'id' => '019ecba7-eb46-73cd-aa84-a4a5b6dc189f',
        'name' => 'Test Account',
        'email' => 'test@example.com',
        'pool_type' => 'free',
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

    // Create a test file
    Storage::fake('local');
    $filePath = 'uploads/test.pdf';
    Storage::put($filePath, 'fake pdf content');

    // Create content source
    $source = ContentSource::factory()
        ->for($user)
        ->withType(SourceType::File)
        ->pending()
        ->create([
            'file_ref' => $filePath,
            'title' => 'Test PDF',
        ]);

    // Mock NotebookLMService
    $notebookLMService = Mockery::mock(NotebookLMService::class);

    $notebookLMService->shouldReceive('addSourceFile')
        ->once()
        ->with($techAccount->id, $notebook->notebook_id, Mockery::any())
        ->andReturn(new SourceDTO(id: 'source-1', title: 'Test File', url: null, created_at: now()->toISOString(), status: 'ready', kind: 'file'));

    $notebookLMService->shouldReceive('waitUntilReady')
        ->once()
        ->andReturn(new SourceDTO(id: 'source-1', title: 'Test File', url: null, created_at: now()->toISOString(), status: 'ready', kind: 'file'));

    $notebookLMService->shouldReceive('getSourceFulltext')
        ->once()
        ->andReturn(new SourceFulltextDTO(source_id: 'source-1', title: 'Test File', content: 'This is extracted text from file', url: null, char_count: 80));

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
    $extractor = new NlmFileExtractor($accountService, $notebookLMService);
    $extractor->extract($source);

    // Refresh source from database
    $source->refresh();

    // Assert extraction status
    expect($source->extraction_status)->toBe(ExtractionStatus::Extracted);

    // Assert OriginalItem was created
    $originalItem = OriginalItem::where('content_source_id', $source->id)->first();
    expect($originalItem)->not->toBeNull();
    expect($originalItem->title)->toBe('Test File');
    expect($originalItem->full_text)->toBe('This is extracted text from file');
    expect($originalItem->source_url)->toBeNull(); // File, not URL
    expect($originalItem->word_count)->toBeGreaterThan(0);
});

test('NlmFileExtractor deletes file in finally block even on exception', function (): void {
    // Create user and tech account first
    $user = User::factory()->create();
    $techAccount = TechAccount::create([
        'id' => '019ecba8-bede-700e-88aa-50be0c6ece2d',
        'name' => 'Test Account',
        'email' => 'test2@example.com',
        'pool_type' => 'free',
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

    // Create a test file
    Storage::fake('local');
    $filePath = 'uploads/test.pdf';
    Storage::put($filePath, 'fake pdf content');

    // Create content source
    $source = ContentSource::create([
        'user_id' => $user->id,
        'type' => 'file',
        'file_ref' => $filePath,
        'title' => 'Test File',
        'extraction_status' => 'pending',
    ]);

    // Mock NotebookLMService to throw exception
    $notebookLMService = Mockery::mock(NotebookLMService::class);

    $notebookLMService->shouldReceive('addSourceFile')
        ->once()
        ->andReturn(new SourceDTO(id: 'source-1', title: 'Test File', url: null, created_at: now()->toISOString(), status: 'ready', kind: 'file'));

    $notebookLMService->shouldReceive('waitUntilReady')
        ->once()
        ->andThrow(new Exception('Indexing failed'));

    // deleteSource should still be called in finally block
    $notebookLMService->shouldReceive('deleteSource')
        ->once()
        ->andReturn(true);

    // Mock AccountService
    $accountService = Mockery::mock(AccountService::class);
    $accountService->shouldReceive('getAvailableTechNotebook')
        ->once()
        ->andReturn($notebook);
    $accountService->shouldReceive('acquireTechNotebookLock')
        ->once()
        ->andReturn(true);
    $accountService->shouldReceive('releaseTechNotebookLock')
        ->once()
        ->andReturn(null);

    // Create extractor and run extraction
    $extractor = new NlmFileExtractor($accountService, $notebookLMService);

    expect(fn () => $extractor->extract($source))->toThrow(Exception::class);

    // Refresh source from database
    $source->refresh();

    // Assert extraction status is error
    expect($source->extraction_status)->toBe(ExtractionStatus::Error);
    expect($source->error_message)->toBe('Indexing failed');
});

test('NlmFileExtractor handles file not found error', function (): void {
    // Create user
    $user = User::factory()->create();

    // Create content source with non-existent file
    $source = ContentSource::create([
        'user_id' => $user->id,
        'type' => 'file',
        'file_ref' => 'uploads/non-existent.pdf',
        'title' => 'Test File',
        'extraction_status' => 'pending',
    ]);

    // Mock dependencies (won't be called)
    $notebookLMService = Mockery::mock(NotebookLMService::class);
    $accountService = Mockery::mock(AccountService::class);

    // Create extractor and run extraction
    $extractor = new NlmFileExtractor($accountService, $notebookLMService);
    $extractor->extract($source);

    // Refresh source from database
    $source->refresh();

    // Assert extraction status is error
    expect($source->extraction_status)->toBe(ExtractionStatus::Error);
    expect($source->error_message)->toContain('File not found');
});
