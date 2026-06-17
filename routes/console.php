<?php

use App\Console\Commands\RetryStaleUploadsCommand;
use App\Domain\NotebookLM\Jobs\CheckAccountHealth;
use App\Jobs\CleanupOrphanedSourcesJob;
use App\Jobs\CleanupStaleTechNotebooksJob;
use App\Jobs\DispatchNotebookBundleJobs;
use App\Jobs\MaintainTechNotebooksPoolJob;
use App\Jobs\ScanForConsolidationJob;
use App\Jobs\ScheduleAutoUpdatesJob;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Maintain tech notebooks pool every 5 minutes
// Schedule::job(new MaintainTechNotebooksPoolJob)->everyFiveMinutes();

// Cleanup stale tech notebooks every 15 minutes
// Schedule::job(new CleanupStaleTechNotebooksJob)->everyFifteenMinutes();

// Schedule auto-updates every 30 minutes
// Schedule::job(new ScheduleAutoUpdatesJob)->everyThirtyMinutes();

// Retry stale uploads every 30 minutes
// Schedule::command(RetryStaleUploadsCommand::class)->everyThirtyMinutes();

// Scan for consolidation opportunities daily at 03:00
// Schedule::job(new ScanForConsolidationJob)->dailyAt('03:00');

// Cleanup orphaned sources daily at 04:00
// Schedule::job(new CleanupOrphanedSourcesJob)->dailyAt('04:00');

// Build bundles for notebooks with unbundled items every 5 minutes
// Schedule::job(new DispatchNotebookBundleJobs)->everyFiveMinutes();

// Schedule::job(new CheckAccountHealth)->everyMinute();

Schedule::command('pulse:check')->everyMinute();
Schedule::command('pulse:ingest')->everyMinute();
