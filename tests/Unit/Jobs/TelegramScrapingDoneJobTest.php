<?php

declare(strict_types=1);

namespace Tests\Unit\Jobs;

use App\Enums\ExtractionStatus;
use App\Enums\SourceDraftStatus;
use App\Jobs\TelegramScrapingDoneJob;
use App\Models\ContentSource;
use App\Models\SourceDraft;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class TelegramScrapingDoneJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_job_sets_extraction_status_to_extracted(): void
    {
        $source = ContentSource::factory()->uploading()->create();

        $job = new TelegramScrapingDoneJob($source->id);
        $job->handle();

        $this->assertEquals(ExtractionStatus::Extracted, $source->fresh()->extraction_status);
    }

    public function test_job_updates_draft_status_to_awaiting_index(): void
    {
        $source = ContentSource::factory()->uploading()->create();

        $draft = SourceDraft::factory()->create([
            'content_source_id' => $source->id,
            'status' => SourceDraftStatus::Processing,
        ]);

        $job = new TelegramScrapingDoneJob($source->id);
        $job->handle();

        $this->assertEquals(SourceDraftStatus::AwaitingIndex, $draft->fresh()->status);
    }

    public function test_job_handles_missing_source(): void
    {
        $job = new TelegramScrapingDoneJob('non-existent-id');
        $job->handle(); // Should not throw exception

        $this->assertTrue(true); // If we get here, test passes
    }
}
