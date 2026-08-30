<?php

declare(strict_types=1);

namespace App\Domain\NotebookLM\Jobs;

use App\Domain\NotebookLM\NotebookLMService;
use App\Enums\TechAccountStatus;
use App\Models\TechAccount;
use Exception;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
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
    use InteractsWithQueue, Queueable;

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
        } catch (Exception $e) {
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
        $account = TechAccount::find($accountId);

        if (! $account) {
            Log::warning('Account not found in database', [
                'account_id' => $accountId,
            ]);
            Log::warning('еуеку', []);

            return;
        }

        $mtimeAgeSeconds = $health['mtime_age_seconds'] ?? null;
        $mtime = $health['mtime'] ?? 'unknown';
        $isConnected = $health['is_connected'] ?? false;
        $status = $health['status'] ?? 'unknown';

        // Determine if account is degraded
        $isDegraded = false;
        $reason = null;

        if (! $isConnected) {
            $isDegraded = true;
            $reason = 'Client not connected';
        } elseif ($mtimeAgeSeconds !== null && $mtimeAgeSeconds > 600) {
            $isDegraded = true;
            $reason = "storage_state.json is stale (mtime_age_seconds: {$mtimeAgeSeconds}s > 600s)";
        } elseif ($mtime !== 'healthy') {
            $isDegraded = true;
            $reason = "mtime is not healthy: {$mtime}";
        } elseif ($status !== 'healthy') {
            $isDegraded = true;
            $reason = "Account status is not healthy: {$status}";
        }

        // Update account status
        if ($isDegraded && $account->status !== TechAccountStatus::Inactive) {
            $account->update([
                'status' => TechAccountStatus::Inactive,
            ]);

            Log::warning('Account marked as inactive', [
                'account_id' => $accountId,
                'reason' => $reason,
                'health' => $health,
            ]);
        } elseif (! $isDegraded && ! in_array($account->status, [TechAccountStatus::Active, TechAccountStatus::Banned])) {
            // Activate if healthy and not already active or banned
            $account->update([
                'status' => TechAccountStatus::Active,
            ]);

            Log::info('Account activated', [
                'account_id' => $accountId,
                'previous_status' => $account->status->value,
            ]);
        }
    }
}
