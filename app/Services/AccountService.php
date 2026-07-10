<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\TechAccountStatus;
use App\Exceptions\DailyLimitExceededException;
use App\Models\TechAccount;
use App\Models\TechAccountUsage;
use App\Models\TechNotebook;
use Illuminate\Support\Facades\DB;

class AccountService
{
    /**
     * Get available tech notebook with most free slots
     */
    public function getAvailableTechNotebook(string $type, array $excludeIds = []): ?TechNotebook
    {
        return TechNotebook::where('type', $type)
            ->whereIn('status', ['idle', 'busy'])
            ->whereRaw('sources_count < max_sources')
            ->when(! empty($excludeIds), fn ($q) => $q->whereNotIn('id', $excludeIds))
            ->orderByRaw('max_sources - sources_count DESC')
            ->first();
    }

    /**
     * Acquire distributed lock on tech notebook using DB transaction with pessimistic lock
     */
    public function acquireTechNotebookLock(TechNotebook $notebook, string $lockKey): bool
    {
        return DB::transaction(function () use ($notebook, $lockKey) {
            $lockedNotebook = TechNotebook::where('id', $notebook->id)
                ->lockForUpdate()
                ->first();

            if (! $lockedNotebook) {
                return false;
            }

            if ($lockedNotebook->locked_at !== null && $lockedNotebook->locked_by !== null) {
                $isOwnDanglingLock = $lockedNotebook->locked_by === $lockKey;
                $isStale = $lockedNotebook->locked_at->diffInMinutes(now()) > 15;

                if ($isOwnDanglingLock || $isStale) {
                    // Свой же зависший лок от убитого предыдущего запуска — забираем сразу,
                    // не дожидаясь 15-минутного окна staleness.
                    $lockedNotebook->update([
                        'locked_at' => null,
                        'locked_by' => null,
                        'status' => 'idle',
                    ]);
                } else {
                    return false; // занято реально другим воркером
                }
            }

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

    /**
     * Get the best available tech account for asking a question.
     *
     * Selects the account with the minimum usage count for today
     * that hasn't exceeded its daily limit.
     *
     * @throws DailyLimitExceededException when all accounts have reached their daily limit
     */
    public function getAccountForAsk(): TechAccount
    {
        $account = TechAccount::query()
            ->select('tech_accounts.*')
            ->where('status', TechAccountStatus::Active)
            ->leftJoin('tech_account_usages', function ($join) {
                $join->on('tech_accounts.id', '=', 'tech_account_usages.tech_account_id')
                    ->where('tech_account_usages.date', '=', today());
            })
            ->orderByRaw('COALESCE(tech_account_usages.count, 0) ASC')
            ->first();

        if (! $account) {
            throw new DailyLimitExceededException;
        }

        $used = $account->todayUsage?->count ?? 0;

        if ($used >= $account->tierLimit->chats_per_day) {
            throw new DailyLimitExceededException;
        }

        return $account;
    }

    public function incrementAskCount(TechAccount $account)
    {
        TechAccountUsage::upsert(
            [['tech_account_id' => $account->id, 'date' => today(), 'count' => 1]],
            ['tech_account_id', 'date'],
            ['count' => DB::raw('tech_account_usages.count + 1')]
        );
    }
}
