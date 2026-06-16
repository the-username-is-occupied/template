<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\TechAccountPoolType;
use App\Enums\TechAccountStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TechAccount extends Model
{
    use HasFactory;
    use HasUuids;

    protected $table = 'tech_accounts';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'name',
        'email',
        'pool_type',
        'status',
        'cookie_path',
        'notebooks_count',
        'chats_today',
        'chats_reset_at',
        'last_used_at',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'pool_type' => TechAccountPoolType::class,
            'status' => TechAccountStatus::class,
            'notebooks_count' => 'integer',
            'chats_today' => 'integer',
            'chats_reset_at' => 'datetime',
            'last_used_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function tierLimit(): BelongsTo
    {
        return $this->belongsTo(AccountTierLimit::class, 'pool_type', 'tier');
    }

    public function notebooks()
    {
        return $this->hasMany(TechNotebook::class, 'account_id');
    }
}
