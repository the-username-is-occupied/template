<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\BundleConsolidationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

class ScanForConsolidationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 1;

    /**
     * Execute the job.
     */
    public function handle(BundleConsolidationService $consolidationService): void
    {
        $consolidationService->scanAndDispatchConsolidation();
    }
}
