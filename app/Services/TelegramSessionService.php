<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Notebook;
use App\Models\TgUserSession;

class TelegramSessionService
{
    /**
     * Handle the /start command - create or update user session
     */
    public function handleStartCommand(int $tgUserId, ?string $baseId = null): TgUserSession
    {
        if (is_null($baseId)) {
            return $this->clearActiveBase($tgUserId);
        }

        if (Notebook::query()->where('id', $baseId)->exists()) {
            return $this->clearActiveBase($tgUserId);
        }

        return $this->setActiveBase($tgUserId, $baseId);
    }

    /**
     * Get the active base for a Telegram user
     */
    public function getActiveBase(int $tgUserId): ?Notebook
    {
        $session = TgUserSession::with('activeBase')
            ->where('tg_user_id', $tgUserId)
            ->first();

        return $session?->activeBase;
    }

    /**
     * Set the active base for a Telegram user
     */
    public function setActiveBase(int $tgUserId, string $baseId): TgUserSession
    {
        return TgUserSession::updateOrCreate(
            ['tg_user_id' => $tgUserId],
            ['active_base_id' => $baseId]
        );
    }

    /**
     * Clear the active base for a Telegram user
     */
    public function clearActiveBase(int $tgUserId): TgUserSession
    {
        return TgUserSession::updateOrCreate(
            ['tg_user_id' => $tgUserId],
            ['active_base_id' => null]
        );
    }

    public function ask() {}
}
