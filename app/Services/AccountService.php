<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\TechNotebook;
use Illuminate\Support\Facades\DB;

class AccountService
{
    /**
     * Get available tech notebook with most free slots
     */
    public function getAvailableTechNotebook(string $type): ?TechNotebook
    {
        return TechNotebook::where('type', $type)
            ->whereIn('status', ['idle', 'busy'])
            ->whereRaw('sources_count < max_sources')
            ->orderByRaw('max_sources - sources_count DESC')
            ->first();
    }

    /**
     * Acquire distributed lock on tech notebook using DB transaction with pessimistic lock
     */
    public function acquireTechNotebookLock(TechNotebook $notebook, string $lockKey): bool
    {
        return DB::transaction(function () use ($notebook, $lockKey) {
            // Reload with pessimistic lock
            $lockedNotebook = TechNotebook::where('id', $notebook->id)
                ->lockForUpdate()
                ->first();

            if (! $lockedNotebook) {
                return false;
            }

            // Check if already locked
            if ($lockedNotebook->locked_at !== null && $lockedNotebook->locked_by !== null) {
                // Check if lock is stale (older than 15 minutes)
                if ($lockedNotebook->locked_at->diffInMinutes(now()) > 15) {
                    // Force release stale lock
                    $lockedNotebook->update([
                        'locked_at' => null,
                        'locked_by' => null,
                        'status' => 'idle',
                    ]);
                } else {
                    return false; // Still locked by someone else
                }
            }

            // Acquire lock
            $lockedNotebook->update([
                'locked_at' => now(),
                'locked_by' => $lockKey,
                'status' => 'busy',
            ]);

            return true;
        });
    }

    /**
     * Release distributed lock on tech notebook
     */
    public function releaseTechNotebookLock(TechNotebook $notebook): void
    {
        DB::transaction(function () use ($notebook) {
            $lockedNotebook = TechNotebook::where('id', $notebook->id)
                ->lockForUpdate()
                ->first();

            if ($lockedNotebook) {
                $newStatus = $lockedNotebook->sources_count >= $lockedNotebook->max_sources
                    ? 'full'
                    : 'idle';

                $lockedNotebook->update([
                    'locked_at' => null,
                    'locked_by' => null,
                    'status' => $newStatus,
                ]);
            }
        });
    }

    /**
     * Increment sources count on tech notebook
     */
    public function incrementSourcesCount(TechNotebook $notebook, int $count): void
    {
        DB::transaction(function () use ($notebook, $count) {
            $lockedNotebook = TechNotebook::where('id', $notebook->id)
                ->lockForUpdate()
                ->first();

            if ($lockedNotebook) {
                $lockedNotebook->increment('sources_count', $count);

                // Update status if full
                if ($lockedNotebook->sources_count >= $lockedNotebook->max_sources) {
                    $lockedNotebook->update(['status' => 'full']);
                }
            }
        });
    }

    /**
     * Decrement sources count on tech notebook
     */
    public function decrementSourcesCount(TechNotebook $notebook, int $count): void
    {
        DB::transaction(function () use ($notebook, $count) {
            $lockedNotebook = TechNotebook::where('id', $notebook->id)
                ->lockForUpdate()
                ->first();

            if ($lockedNotebook) {
                $lockedNotebook->decrement('sources_count', $count);

                // Update status if was full
                if ($lockedNotebook->status === 'full' && $lockedNotebook->sources_count < $lockedNotebook->max_sources) {
                    $lockedNotebook->update(['status' => 'idle']);
                }
            }
        });
    }
}
