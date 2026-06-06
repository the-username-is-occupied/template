<?php

use App\Domain\NotebookLM\Jobs\CheckAccountHealth;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Check NotebookLM account health every minute
Schedule::job(new CheckAccountHealth)->everyMinute();
