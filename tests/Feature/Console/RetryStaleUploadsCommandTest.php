<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Console\Commands\RetryStaleUploadsCommand;
use App\Enums\ExtractionStatus;
use App\Jobs\ProcessSourceJob;
use App\Models\ContentSource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

final class RetryStaleUploadsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_resets_stale_uploads(): void
    {
        // Mock the job dispatch
        Bus::fake();

        // Create a stale upload (more than 30 minutes old)
        $staleSource = ContentSource::factory()->create([
            'extraction_status' => ExtractionStatus::Uploading,
            'updated_at' => now()->subMinutes(31),
        ]);

        // Create a fresh upload (less than 30 minutes old)
        $freshSource = ContentSource::factory()->create([
            'extraction_status' => ExtractionStatus::Uploading,
            'updated_at' => now()->subMinutes(10),
        ]);

        $this->artisan(RetryStaleUploadsCommand::class)
            ->assertExitCode(0);

        // Stale source should be reset
        $this->assertEquals(ExtractionStatus::Pending, $staleSource->fresh()->extraction_status);
        $this->assertEquals('stale_reset', $staleSource->fresh()->error_code);

        // Fresh source should not be reset
        $this->assertEquals(ExtractionStatus::Uploading, $freshSource->fresh()->extraction_status);

        // Verify job was dispatched
        Bus::assertDispatched(ProcessSourceJob::class);
    }

    public function test_command_dispatches_retry_job(): void
    {
        // Mock the job dispatch
        Bus::fake();

        $staleSource = ContentSource::factory()->create([
            'extraction_status' => ExtractionStatus::Uploading,
            'updated_at' => now()->subMinutes(31),
        ]);

        $this->artisan(RetryStaleUploadsCommand::class)
            ->assertExitCode(0);

        // Verify the source was reset
        $this->assertEquals(ExtractionStatus::Pending, $staleSource->fresh()->extraction_status);
        $this->assertEquals('stale_reset', $staleSource->fresh()->error_code);

        // Verify job was dispatched
        Bus::assertDispatched(ProcessSourceJob::class);
    }
}
