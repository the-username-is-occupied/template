<?php

declare(strict_types=1);

namespace Tests\Unit;

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
use App\Services\Extractors\WebsiteExtractor;
use Exception;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class WebsiteExtractorTest extends TestCase
{
    use RefreshDatabase;

    public function test_website_extractor_loads_url_extracts_text_and_deletes_source_in_finally(): void
    {
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
        $source = ContentSource::factory()
            ->for($user)
            ->withType(SourceType::Website)
            ->withUrl('https://example.com')
            ->pending()
            ->create(['title' => 'Test Website']);
        Log::info('TEST: ContentSource created', ['source_id' => $source->id, 'url' => $source->url]);
        // Prevent events from being dispatched
        Event::fake();

        // Create mock instances
        $notebookLMService = $this->mock(NotebookLMService::class);
        $notebookLMService->shouldReceive('addSourceUrl')
            ->once()
            ->with($this->anything(), $this->anything(), $this->anything())
            ->andReturn(new SourceDTO(
                id: 'source-1',
                title: 'Example Domain',
                url: 'https://example.com',
                created_at: now()->toISOString(),
                status: 'ready',
                kind: 'website'
            ));
        $notebookLMService->shouldReceive('waitUntilReady')
            ->once()
            ->andReturn(new SourceDTO(id: 'source-1', title: 'Example Domain', url: 'https://example.com', created_at: now()->toISOString(), status: 'ready', kind: 'website'));
        $notebookLMService->shouldReceive('getSourceFulltext')
            ->once()
            ->andReturn(new SourceFulltextDTO(source_id: 'source-1', title: 'Example Domain', content: 'This is example text content', url: 'https://example.com', char_count: 50));
        $notebookLMService->shouldReceive('deleteSource')
            ->once()
            ->with($notebook->account_id, $notebook->notebook_id, 'source-1')
            ->andReturn(true);

        $accountService = $this->mock(AccountService::class);
        $accountService->shouldReceive('getAvailableTechNotebook')
            ->once()
            ->with('source_extractor')
            ->andReturn($notebook);
        $accountService->shouldReceive('acquireTechNotebookLock')
            ->once()
            ->with($this->anything(), $this->anything())
            ->andReturn(true);
        $accountService->shouldReceive('releaseTechNotebookLock')
            ->once()
            ->with($this->anything())
            ->andReturn(null);
        $accountService->shouldReceive('incrementSourcesCount')
            ->once()
            ->with($this->anything(), $this->anything())
            ->andReturn(null);

        // Bind mocks to container
        $this->app->instance(NotebookLMService::class, $notebookLMService);
        $this->app->instance(AccountService::class, $accountService);

        // Create extractor via container
        $extractor = $this->app->make(WebsiteExtractor::class);

        try {
            $extractor->extract($source);
        } catch (Exception $e) {
            throw $e;
        }

        // Refresh source from database
        $source->refresh();

        // Assert extraction status
        $this->assertEquals(ExtractionStatus::Extracted, $source->extraction_status);

        // Assert OriginalItem was created
        $originalItem = OriginalItem::where('content_source_id', $source->id)->first();
        $this->assertNotNull($originalItem);
        $this->assertEquals('Example Domain', $originalItem->title);
        $this->assertEquals('This is example text content', $originalItem->full_text);
        $this->assertEquals('https://example.com', $originalItem->source_url);
        $this->assertGreaterThan(0, $originalItem->word_count);
    }

    public function test_website_extractor_deletes_source_in_finally_block_even_on_exception(): void
    {
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
            'type' => SourceType::Website,
            'url' => 'https://example.com',
            'title' => 'Test Website',
            'extraction_status' => ExtractionStatus::Pending,
        ]);

        // Prevent events from being dispatched
        Event::fake();

        // Create mock instances
        $notebookLMService = $this->mock(NotebookLMService::class);
        $notebookLMService->shouldReceive('addSourceUrl')
            ->once()
            ->with($this->anything(), $notebook->notebook_id, 'https://example.com')
            ->andReturn(new SourceDTO(id: 'source-1', title: 'Test', url: null, created_at: now()->toISOString(), status: 'ready', kind: 'website'));
        $notebookLMService->shouldReceive('waitUntilReady')
            ->once()
            ->andThrow(new Exception('Indexing failed'));
        // deleteSource should still be called in finally block
        $notebookLMService->shouldReceive('deleteSource')
            ->once()
            ->with($notebook->account_id, $notebook->notebook_id, 'source-1')
            ->andReturn(true);

        $accountService = $this->mock(AccountService::class);
        $accountService->shouldReceive('getAvailableTechNotebook')
            ->once()
            ->with('source_extractor')
            ->andReturn($notebook);
        $accountService->shouldReceive('acquireTechNotebookLock')
            ->once()
            ->with($notebook, $this->anything())
            ->andReturn(true);
        $accountService->shouldReceive('releaseTechNotebookLock')
            ->once()
            ->with($notebook)
            ->andReturn(null);

        // Bind mocks to container
        $this->app->instance(NotebookLMService::class, $notebookLMService);
        $this->app->instance(AccountService::class, $accountService);

        // Create extractor via container
        $extractor = $this->app->make(WebsiteExtractor::class);

        $this->expectException(Exception::class);
        $extractor->extract($source);

        // Refresh source from database
        $source->refresh();

        // Assert extraction status is error
        $this->assertEquals(ExtractionStatus::Error, $source->extraction_status);
        $this->assertEquals('Indexing failed', $source->error_message);
    }
}
