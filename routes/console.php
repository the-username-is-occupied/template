<?php

use App\Console\Commands\RetryStaleUploadsCommand;
use App\Domain\NotebookLM\Jobs\CheckAccountHealth;
use App\Jobs\CleanupStaleTechNotebooksJob;
use App\Jobs\DispatchNotebookBundleJobs;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Check NotebookLM account health every minute
// Schedule::job(new CheckAccountHealth)->everyMinute();

// Retry stale uploads every 30 minutes
// Schedule::command(RetryStaleUploadsCommand::class)->everyThirtyMinutes();

// Cleanup stale tech notebooks every 15 minutes
// Schedule::job(new CleanupStaleTechNotebooksJob)->everyFifteenMinutes();

// Build bundles for notebooks with unbundled items every 5 minutes
// Schedule::job(new DispatchNotebookBundleJobs)->everyFiveMinutes();
