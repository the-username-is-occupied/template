<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChatMessage extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'chat_id',
        'tech_account_id',
        'role',
        'content',
        'result',
        'is_success',
    ];

    protected function casts(): array
    {
        return [
            'result' => 'array',
            'is_success' => 'boolean',
        ];
    }

    public function chat(): BelongsTo
    {
        return $this->belongsTo(Chat::class);
    }

    public function techAccount(): BelongsTo
    {
        return $this->belongsTo(TechAccount::class);
    }
}
