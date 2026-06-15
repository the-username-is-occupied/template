<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

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

    protected function casts(): array
    {
        return [
            'sources_count' => 'integer',
            'max_sources' => 'integer',
            'metadata' => 'array',
            'locked_at' => 'datetime',
        ];
    }

    public function account()
    {
        return $this->belongsTo(TechAccount::class, 'account_id');
    }

    public function isAvailable(): bool
    {
        return in_array($this->status, ['idle', 'busy'])
            && $this->sources_count < $this->max_sources;
    }

    public function hasAvailableSlots(): bool
    {
        return $this->sources_count < $this->max_sources;
    }

    public function getAvailableSlots(): int
    {
        return max(0, $this->max_sources - $this->sources_count);
    }
}
