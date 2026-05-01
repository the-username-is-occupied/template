<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\TokenUsageLogFactory;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TokenUsageLog extends Model
{
    /** @use HasFactory<TokenUsageLogFactory> */
    use HasFactory;

    public const string OperationIndexing = 'indexing';

    public const string OperationQuery = 'query';

    protected $fillable = [
        'user_space_id',
        'operation_type',
        'model_name',
        'prompt_tokens',
        'completion_tokens',
        'estimated_cost_usd',
    ];

    public const ?string UPDATED_AT = null;

    public function userSpace(): BelongsTo
    {
        return $this->belongsTo(UserSpace::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'prompt_tokens' => 'integer',
            'completion_tokens' => 'integer',
            'estimated_cost_usd' => 'decimal:8',
        ];
    }

    #[Scope]
    protected function latestFirst(Builder $query): void
    {
        $query->latest('id');
    }
}
