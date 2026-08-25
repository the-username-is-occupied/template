<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\NotebookLM\DTOs\AskResultDTO;
use Illuminate\Database\Eloquent\Builder;
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
        'follow_up_id',
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

    public function followUp(): BelongsTo
    {
        return $this->belongsTo(ChatMessage::class, 'follow_up_id');
    }

    public function askDto()
    {
        return AskResultDTO::from($this->result);
    }

    public function scopeSuccess(Builder $builder)
    {
        return $builder->where('is_success', true);
    }
}
