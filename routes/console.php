<?php

use App\Console\Commands\RetryStaleUploadsCommand;
use App\Domain\NotebookLM\Jobs\CheckAccountHealth;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Check NotebookLM account health every minute
// Schedule::job(new CheckAccountHealth)->everyMinute();

// Retry stale uploads every 30 minutes
Schedule::command(RetryStaleUploadsCommand::class)->everyThirtyMinutes();
