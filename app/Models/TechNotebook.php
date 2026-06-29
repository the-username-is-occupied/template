<?php

declare(strict_types=1);

namespace App\Models;

use App\Services\AccountService;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;

class TechNotebook extends Model
{
    use HasFactory;
    use HasUuids;

    protected $table = 'tech_notebooks';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'account_id',
        'notebook_id',
        'type',
        'sources_count',
        'max_sources',
        'status',
        'locked_at',
        'locked_by',
        'metadata',
    ];

    protected $with = ['tierLimit'];

    protected function casts(): array
    {
        return [
            'sources_count' => 'integer',
            'max_sources' => 'integer',
            'metadata' => 'array',
            'locked_at' => 'datetime',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(TechAccount::class, 'account_id');
    }

    public function tierLimit(): HasOneThrough
    {
        return $this->hasOneThrough(
            AccountTierLimit::class,
            TechAccount::class,
            'id', // Foreign key on TechAccount table
            'tier', // Local key on AccountTierLimit table
            'account_id', // Local key on TechNotebook table
            'pool_type' // Foreign key on TechAccount table
        );
    }

    public function isAvailable(): bool
    {
        return in_array($this->status, ['idle', 'busy'])
            && $this->hasAvailableSlots();
    }

    public function hasAvailableSlots(): bool
    {
        return $this->sources_count < $this->max_sources;
    }

    public function getAvailableSlots(): int
    {
        return max(0, $this->max_sources - $this->sources_count);
    }

    protected function getMaxSourcesAttribute(): int
    {
        return $this->tierLimit?->sources_per_notebook ?? 50;
    }

    public function clean()
    {
        $this->account()->first()->service()->cleanupNotebookSources($this->notebook_id);
        (new AccountService)->releaseTechNotebookLock($this);
    }
}
