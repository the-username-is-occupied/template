<?php

declare(strict_types=1);

namespace App\Models;

use App\Share\Models\Traits\HasBelongsToUser;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Chat extends Model
{
    use HasBelongsToUser, HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'user_id',
        'notebook_id',
    ];

    public function notebook(): BelongsTo
    {
        return $this->belongsTo(Notebook::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(ChatMessage::class);
    }

    public function scopeNotebook(Builder $builder, Notebook $notebook)
    {
        return $builder->where('notebook_id', $notebook->id);
    }
}
