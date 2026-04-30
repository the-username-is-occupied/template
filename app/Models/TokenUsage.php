<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TokenUsage extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'userspace',
        'operation',
        'provider',
        'model',
        'input_tokens',
        'output_tokens',
        'input_cost',
        'output_cost',
        'total_cost',
        'source_file',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'input_tokens' => 'integer',
            'output_tokens' => 'integer',
            'input_cost' => 'decimal:6',
            'output_cost' => 'decimal:6',
            'total_cost' => 'decimal:6',
            'created_at' => 'datetime',
        ];
    }
}
