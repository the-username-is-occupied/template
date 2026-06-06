<?php

namespace App\Jobs;

use App\Domain\NotebookLM\NotebookLMService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class TechAccHealth implements ShouldQueue
{
    use Queueable;

    public int $retryAfter = 60;

    /**
     * Create a new job instance.
     */
    public function __construct(
        private readonly NotebookLMService $service = new NotebookLMService
    ) {}

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $healthData = $this->service->healthAccounts();
    }
}
