<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\NotebookLM\NotebookLMService;
use App\Models\TechNotebook;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

class CleanupStaleTechNotebooksJob implements ShouldQueue
{
    use InteractsWithQueue, Queueable;

    public function handle(NotebookLMService $notebookLMService): void
    {
        // Find stale notebooks (locked for more than 15 minutes)
        $staleNotebooks = TechNotebook::where('status', 'busy')
            ->whereNotNull('locked_at')
            ->where('locked_at', '<', Carbon::now()->subMinutes(15))
            ->get();

        foreach ($staleNotebooks as $notebook) {
            try {
                $this->cleanupNotebook($notebook, $notebookLMService);
            } catch (\Exception $e) {
                Log::error("Failed to cleanup notebook {$notebook->id}: ".$e->getMessage());

                // Mark as degraded
                $notebook->update(['status' => 'degraded']);
            }
        }
    }

    private function cleanupNotebook(TechNotebook $notebook, NotebookLMService $notebookLMService): void
    {
        // Get list of sources in the notebook
        $sources = $notebookLMService->listSources(
            $notebook->account_id,
            $notebook->notebook_id
        );

        $allDeleted = true;

        // Delete each source
        foreach ($sources as $source) {
            try {
                $notebookLMService->deleteSource(
                    $notebook->account_id,
                    $notebook->notebook_id,
                    $source->id
                );
            } catch (\Exception $e) {
                Log::error("Failed to delete source {$source->id} from notebook {$notebook->id}: ".$e->getMessage());
                $allDeleted = false;
            }
        }

        // Update notebook status
        if ($allDeleted) {
            $notebook->update([
                'status' => 'idle',
                'sources_count' => 0,
                'locked_at' => null,
                'locked_by' => null,
            ]);

            Log::info("Successfully cleaned up stale notebook {$notebook->id}");
        } else {
            $notebook->update(['status' => 'degraded']);
            Log::warning("Notebook {$notebook->id} marked as degraded due to cleanup failures");
        }
    }
}
