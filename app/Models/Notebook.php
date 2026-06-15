<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Notebook extends Model
{
    use HasFactory;
    use HasUuids;

    protected $table = 'notebooks';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'user_id',
        'nlm_notebook_id',
        'title',
        'system_prompt',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'user_id' => 'string',
            'nlm_notebook_id' => 'string',
            'title' => 'string',
            'system_prompt' => 'string',
            'status' => 'string',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function mdBundles(): HasMany
    {
        return $this->hasMany(MdBundle::class);
    }

    public function sourceDrafts(): HasMany
    {
        return $this->hasMany(SourceDraft::class, 'knowledge_base_id');
    }

    public function notebookContentSources(): HasMany
    {
        return $this->hasMany(ContentSource::class);
    }
}
