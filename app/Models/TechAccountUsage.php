<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TechAccountUsage extends Model
{
    protected $fillable = [
        'tech_account_id',
        'date',
        'count',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'count' => 'integer',
        ];
    }

    public function techAccount(): BelongsTo
    {
        return $this->belongsTo(TechAccount::class);
    }
}
