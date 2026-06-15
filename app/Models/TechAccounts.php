<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\NotebookLM\NotebookLMServiceDecorator;
use App\Enums\TechAccountPoolType;
use App\Enums\TechAccountStatus;
use Database\Factories\TechAccountsFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TechAccounts extends Model
{
    /** @use HasFactory<TechAccountsFactory> */
    use HasFactory;

    use HasUuids;

    protected $table = 'tech_accounts';

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * @var list<string>
     */
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
        ];
    }

    public function tierLimit(): BelongsTo
    {
        return $this->belongsTo(AccountTierLimit::class, 'pool_type', 'tier');
    }

    /**
     * Get the NotebookLM service decorator for this account.
     */
    public function service(): NotebookLMServiceDecorator
    {
        return new NotebookLMServiceDecorator($this);
    }
}
