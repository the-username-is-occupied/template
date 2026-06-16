<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\ContentSource;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;

class CleanupOrphanedSourcesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 1;

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        // Find orphaned sources (older than 7 days, no source_draft, not in any notebook)
        $orphanedSources = ContentSource::where('created_at', '<', now()->subDays(7))
            ->whereDoesntHave('sourceDraft')
            ->whereDoesntHave('notebooks')
            ->get();

        foreach ($orphanedSources as $source) {
            DB::transaction(function () use ($source): void {
                // Delete original items first
                $source->originalItems()->delete();

                // Delete the source
                $source->delete();
            });
        }
    }
}
