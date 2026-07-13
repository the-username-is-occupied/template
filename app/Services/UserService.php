<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\TgUser;
use App\Models\User;
use Illuminate\Support\Str;

class UserService
{
    public function findOrCreateByTgUserId(int $tgUserId, array $tgUserData = []): User
    {
        $tgUser = TgUser::where('tg_user_id', $tgUserId)->first();

        if ($tgUser) {
            return $tgUser->user;
        }

        // Create new user
        $user = User::factory()->create([
            'name' => $tgUserData['first_name'] ?? 'Telegram User',
            'email' => 'tg_'.$tgUserId.'@telegram.temp',
            'password' => Str::random(10),
        ]);

        // Create TgUser relation
        TgUser::create([
            'user_id' => $user->id,
            'tg_user_id' => $tgUserId,
            'username' => $tgUserData['username'] ?? null,
            'first_name' => $tgUserData['first_name'] ?? null,
            'last_name' => $tgUserData['last_name'] ?? null,
        ]);

        return $user->fresh();
    }
}
