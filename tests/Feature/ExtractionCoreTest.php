<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Contracts\SourceExtractorInterface;
use App\Enums\ExtractionStatus;
use App\Enums\SourceDraftStatus;
use App\Enums\SourceType;
use App\Events\ExtractionCompleted;
use App\Exceptions\UnsupportedSourceTypeException;
use App\Jobs\ProcessSourceJob;
use App\Models\ContentSource;
use App\Models\SourceDraft;
use App\Models\User;
use App\Services\ExtractorFactory;
use App\Services\Extractors\TextExtractor;
use App\Services\SourceIndexingService;
use App\Services\SourceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class ExtractionCoreTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private SourceDraft $draft;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        Storage::fake('local');
    }

    public function test_text_extractor_creates_original_item(): void
    {
        $filePath = 'test-file.txt';
        Storage::disk('local')->put($filePath, 'This is a test content with several words for word count.');

        $source = ContentSource::factory()
            ->for($this->user)
            ->withType(SourceType::Text)
            ->pending()
            ->create(['file_ref' => $filePath]);

        $extractor = new TextExtractor;
        $extractor->extract($source);

        $source->refresh();

        $this->assertEquals(ExtractionStatus::Extracted, $source->extraction_status);
        $this->assertDatabaseCount('original_items', 1);

        $item = $source->originalItems()->first();
        $this->assertEquals('test-file.txt', $item->title);
        $this->assertGreaterThan(0, $item->word_count);
        $this->assertStringContainsString('This is a test content', $item->full_text);
    }

    public function test_text_extractor_handles_missing_file(): void
    {
        $source = ContentSource::factory()
            ->for($this->user)
            ->withType(SourceType::Text)
            ->pending()
            ->create(['file_ref' => 'non-existent.txt']);

        $extractor = new TextExtractor;
        $extractor->extract($source);

        $source->refresh();

        $this->assertEquals(ExtractionStatus::Error, $source->extraction_status);
        $this->assertEquals('file_not_found', $source->error_code);
    }

    public function test_extractor_factory_resolves_text_type(): void
    {
        $source = ContentSource::factory()
            ->for($this->user)
            ->withType(SourceType::Text)
            ->create();

        $factory = new ExtractorFactory;
        $extractor = $factory->make($source);

        $this->assertInstanceOf(SourceExtractorInterface::class, $extractor);
        $this->assertInstanceOf(TextExtractor::class, $extractor);
    }

    public function test_extractor_factory_throws_for_unsupported_type(): void
    {
        $source = ContentSource::factory()
            ->for($this->user)
            ->withType(SourceType::TelegramChannel)
            ->create();

        $factory = new ExtractorFactory;

        $this->expectException(UnsupportedSourceTypeException::class);
        $factory->make($source);
    }

    public function test_source_indexing_service_processes_source(): void
    {
        Storage::disk('local')->put('test.txt', 'Test content');

        $source = ContentSource::factory()
            ->for($this->user)
            ->withType(SourceType::Text)
            ->pending()
            ->create(['file_ref' => 'test.txt']);

        $service = new SourceIndexingService(new ExtractorFactory);
        $service->process($source->id);

        $source->refresh();
        $this->assertEquals(ExtractionStatus::Extracted, $source->extraction_status);
    }

    public function test_source_service_confirm_and_process(): void
    {
        Queue::fake();

        $draft = SourceDraft::factory()
            ->forUser($this->user)
            ->withType(SourceType::Text)
            ->withUrl('https://example.com/test.txt')
            ->awaitingConfirm()
            ->create();

        Storage::disk('local')->put('test.txt', 'Test content');

        $service = new SourceService(new ExtractorFactory);
        $source = $service->confirmAndProcess($draft);

        $draft->refresh();
        $this->assertEquals(SourceDraftStatus::Processing, $draft->status);
        $this->assertNotNull($draft->content_source_id);

        Queue::assertPushed(ProcessSourceJob::class, function ($job) use ($source) {
            return $job->contentSourceId === $source->id;
        });
    }

    public function test_source_service_start_indexing(): void
    {
        Queue::fake();

        $source = ContentSource::factory()
            ->for($this->user)
            ->withType(SourceType::TelegramChannel)
            ->extracted()
            ->create();

        $draft = SourceDraft::factory()
            ->forUser($this->user)
            ->withContentSource($source)
            ->awaitingIndex()
            ->create();

        $service = new SourceService(new ExtractorFactory);
        $service->startIndexing($draft, []);

        $draft->refresh();
        $this->assertEquals(SourceDraftStatus::Indexing, $draft->status);

        Queue::assertPushed(ProcessSourceJob::class);
    }

    public function test_api_confirm_endpoint(): void
    {
        Queue::fake();

        Storage::disk('local')->put('test.txt', 'Test content');

        $draft = SourceDraft::factory()
            ->forUser($this->user)
            ->withType(SourceType::Text)
            ->withUrl('https://example.com/test.txt')
            ->awaitingConfirm()
            ->create();

        $response = $this->actingAs($this->user)
            ->postJson("/api/source-drafts/{$draft->id}/confirm");

        $response->assertStatus(200);
        $response->assertJsonStructure(['message', 'content_source_id']);

        $draft->refresh();
        $this->assertEquals(SourceDraftStatus::Processing, $draft->status);
    }

    public function test_api_index_endpoint(): void
    {
        Queue::fake();

        $source = ContentSource::factory()
            ->for($this->user)
            ->withType(SourceType::TelegramChannel)
            ->extracted()
            ->create();

        $draft = SourceDraft::factory()
            ->forUser($this->user)
            ->withContentSource($source)
            ->awaitingIndex()
            ->create();

        $response = $this->actingAs($this->user)
            ->postJson("/api/source-drafts/{$draft->id}/index", [
                'approved_urls' => [],
            ]);

        $response->assertStatus(200);
        $response->assertJson(['message' => 'Indexing started.']);

        $draft->refresh();
        $this->assertEquals(SourceDraftStatus::Indexing, $draft->status);
    }

    public function test_extraction_completed_event_dispatches(): void
    {
        Event::fake();

        $source = ContentSource::factory()->create([
            'user_id' => $this->user->id,
            'type' => SourceType::Text,
        ]);

        event(new ExtractionCompleted($source));

        Event::assertDispatched(ExtractionCompleted::class);
    }

    public function test_process_source_job_is_unique(): void
    {
        $sourceId = 'test-uuid-1234';

        $job1 = new ProcessSourceJob($sourceId);
        $job2 = new ProcessSourceJob($sourceId);

        $this->assertEquals($job1->uniqueId(), $job2->uniqueId());
    }
}
