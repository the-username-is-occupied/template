<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\NotebookLM\NotebookLMService;
use App\Services\TechNotebookPoolService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

class MaintainTechNotebooksPoolJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 1;

    /**
     * Execute the job.
     */
    public function handle(TechNotebookPoolService $poolService, NotebookLMService $notebookLMService): void
    {
        try {
            $poolService->maintain($notebookLMService);
        } catch (\Throwable $e) {
            Log::error('MaintainTechNotebooksPoolJob: Failed to maintain pool', [
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
