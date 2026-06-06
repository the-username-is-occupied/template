<?php

declare(strict_types=1);

namespace App\Domain\NotebookLM\Jobs;

use App\Domain\NotebookLM\NotebookLMService;
use App\Enums\TechAccountStatus;
use App\Models\TechAccounts;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;

/**
 * Job to check health of NotebookLM accounts via FastAPI.
 *
 * This job is scheduled to run every minute via the Laravel scheduler.
 * It polls the FastAPI /health/accounts endpoint and updates the status
 * of tech_accounts based on the health check results.
 */
class CheckAccountHealth implements ShouldQueue
{
    /**
     * The number of seconds to wait before retrying the job.
     */
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
        try {
            $healthData = $this->service->healthAccounts();
        } catch (\Exception $e) {
            Log::error('Failed to fetch health data from FastAPI', [
                'error' => $e->getMessage(),
            ]);

            return;
        }

        foreach ($healthData as $accountId => $health) {
            $this->processAccountHealth($accountId, $health);
        }
    }

    /**
     * Process health data for a single account.
     *
     * @param  array{mtime_age_seconds?: int, mtime: string, is_connected: bool, status: string}  $health
     */
    protected function processAccountHealth(string $accountId, array $health): void
    {
        $account = TechAccounts::find($accountId);

        if (! $account) {
            Log::warning('Account not found in database', [
                'account_id' => $accountId,
            ]);

            return;
        }

        $mtime = $health['mtime'] ?? 'unknown';
        $isConnected = $health['is_connected'] ?? false;
        $status = $health['status'] ?? 'unknown';

        // Determine if account is degraded
        $isDegraded = false;
        $reason = null;

        if (! $isConnected) {
            $isDegraded = true;
            $reason = 'Client not connected';
        } elseif ($mtime === 'stale') {
            $isDegraded = true;
            $reason = 'storage_state.json is stale (mtime > 600s)';
        } elseif ($mtime === 'missing') {
            $isDegraded = true;
            $reason = 'storage_state.json is missing';
        } elseif ($status === 'degraded') {
            $isDegraded = true;
            $reason = 'Account status is degraded';
        }

        // Update account status
        if ($isDegraded && $account->status !== TechAccountStatus::Degraded) {
            $account->update([
                'status' => TechAccountStatus::Degraded,
            ]);

            Log::warning('Account marked as degraded', [
                'account_id' => $accountId,
                'reason' => $reason,
                'health' => $health,
            ]);
        } elseif (! $isDegraded && $account->status === TechAccountStatus::Degraded) {
            // Recover to active if it was degraded
            $account->update([
                'status' => TechAccountStatus::Active,
            ]);

            Log::info('Account recovered from degraded state', [
                'account_id' => $accountId,
            ]);
        }
    }
}
