<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\OriginalItem;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

class DispatchNotebookBundleJobs implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 3;

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $notebookIds = OriginalItem::getNotebookIdsWithUnbundledItems();

        Log::info('Dispatching bundle builds', [
            'notebook_count' => $notebookIds->count(),
            'notebook_ids' => $notebookIds->toArray(),
        ]);

        foreach ($notebookIds as $notebookId) {
            BuildBundlesJob::dispatch($notebookId);
        }
    }
}
