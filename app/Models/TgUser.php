<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\TgUserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TgUser extends Model
{
    /** @use HasFactory<TgUserFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id',
        'tg_user_id',
        'username',
        'first_name',
        'last_name',
    ];

    protected function casts(): array
    {
        return [
            'tg_user_id' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function id()
    {
        return $this->tg_user_id;
    }
}
