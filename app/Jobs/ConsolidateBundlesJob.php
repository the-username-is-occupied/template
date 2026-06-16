<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\MdBundleType;
use App\Models\Notebook;
use App\Services\BundleConsolidationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

class ConsolidateBundlesJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 3;

    /**
     * The maximum number of unhandled exceptions to allow before failing.
     */
    public int $maxExceptions = 1;

    /**
     * Create a new job instance.
     *
     * @param  string  $level  'quarter_to_half' or 'half_to_full'
     */
    public function __construct(
        public readonly string $notebookId,
        public readonly string $level = 'quarter_to_half',
    ) {}

    /**
     * The unique ID of the job.
     */
    public function uniqueId(): string
    {
        return $this->notebookId.'_'.$this->level;
    }

    /**
     * Execute the job.
     */
    public function handle(BundleConsolidationService $consolidationService): void
    {
        $notebook = Notebook::find($this->notebookId);

        if ($notebook === null) {
            Log::warning('ConsolidateBundlesJob: Notebook not found', [
                'notebook_id' => $this->notebookId,
            ]);

            return;
        }

        $consolidationService->consolidate($notebook, MdBundleType::level($this->level));
    }
}
