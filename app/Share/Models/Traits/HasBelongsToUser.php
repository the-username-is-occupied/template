<?php

declare(strict_types=1);

namespace App\Share\Models\Traits;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

trait HasBelongsToUser
{
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeUser(Builder $builder, User $user)
    {
        return $builder->where('user_id', $user->id);
    }
}
