<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\NotebookLM\DTOs\NotebookDTO;
use App\Domain\NotebookLM\NotebookLMService;
use App\Models\TechAccount;
use App\Models\TechNotebook;
use Illuminate\Support\Facades\Log;

class TechNotebookPoolService
{
    /**
     * Target pool configuration: notebook type => target count
     */
    private const TARGET_POOL = [
        'source_extractor' => 5,
        'summary_aggregator' => 1,
        'global_search' => 1,
    ];

    /**
     * Maintain the tech notebooks pool.
     * Creates missing notebooks and recreates degraded ones.
     */
    public function maintain(NotebookLMService $notebookLMService): void
    {
        foreach (self::TARGET_POOL as $type => $targetCount) {
            $activeCount = $this->getActiveCount($type);

            if ($activeCount < $targetCount) {
                $this->createMissingNotebooks(
                    $notebookLMService,
                    $type,
                    $targetCount - $activeCount
                );
            }
        }

        $this->handleDegradedNotebooks($notebookLMService);

        Log::info('TechNotebookPoolService: Pool maintenance completed');
    }

    /**
     * Get count of active notebooks for a specific type.
     */
    private function getActiveCount(string $type): int
    {
        return TechNotebook::where('type', $type)
            ->where('status', '!=', 'degraded')
            ->count();
    }

    /**
     * Create missing tech notebooks.
     */
    private function createMissingNotebooks(
        NotebookLMService $notebookLMService,
        string $type,
        int $count
    ): void {
        for ($i = 0; $i < $count; $i++) {
            try {
                $account = $this->getAccountWithLeastNotebooks();

                if (! $account) {
                    Log::error('TechNotebookPoolService: No accounts available');

                    return;
                }

                $notebookDTO = $notebookLMService->createNotebook(
                    $account->id,
                    "Tech Notebook - {$type} - ".now()->timestamp
                );

                $this->saveTechNotebook($account, $notebookDTO, $type);

                Log::info('TechNotebookPoolService: Created tech notebook', [
                    'type' => $type,
                    'account_id' => $account->id,
                    'nlm_notebook_id' => $notebookDTO->id,
                ]);
            } catch (\Throwable $e) {
                Log::error('TechNotebookPoolService: Failed to create tech notebook', [
                    'type' => $type,
                    'error' => $e->getMessage(),
                ]);

                throw $e;
            }
        }
    }

    /**
     * Handle degraded notebooks by recreating them.
     */
    private function handleDegradedNotebooks(NotebookLMService $notebookLMService): void
    {
        $degraded = TechNotebook::where('status', 'degraded')->get();

        foreach ($degraded as $techNotebook) {
            try {
                $notebookDTO = $notebookLMService->createNotebook(
                    $techNotebook->account_id,
                    "Tech Notebook - {$techNotebook->type} - ".now()->timestamp
                );

                $techNotebook->update([
                    'notebook_id' => $notebookDTO->id,
                    'status' => 'active',
                ]);

                Log::info('TechNotebookPoolService: Recreated degraded tech notebook', [
                    'tech_notebook_id' => $techNotebook->id,
                    'new_notebook_id' => $notebookDTO->id,
                ]);
            } catch (\Throwable $e) {
                Log::error('TechNotebookPoolService: Failed to recreate degraded tech notebook', [
                    'tech_notebook_id' => $techNotebook->id,
                    'error' => $e->getMessage(),
                ]);

                throw $e;
            }
        }
    }

    /**
     * Get account with the least notebooks count.
     */
    private function getAccountWithLeastNotebooks(): ?TechAccount
    {
        return TechAccount::orderBy('notebooks_count')->first();
    }

    /**
     * Save tech notebook to database and increment account counter.
     */
    private function saveTechNotebook(
        TechAccount $account,
        NotebookDTO $notebookDTO,
        string $type
    ): TechNotebook {
        $techNotebook = TechNotebook::create([
            'account_id' => $account->id,
            'notebook_id' => $notebookDTO->id,
            'type' => $type,
        ]);

        $account->increment('notebooks_count');

        return $techNotebook;
    }
}
