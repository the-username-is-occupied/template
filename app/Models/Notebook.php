<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\NotebookLM\NotebookNLMDecorator;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
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
        'tech_account_id',
        'nlm_notebook_id',
        'title',
        'system_prompt',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'user_id' => 'string',
            'tech_account_id' => 'string',
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

    public function techAccount(): BelongsTo
    {
        return $this->belongsTo(TechAccount::class, 'tech_account_id');
    }

    public function mdBundles(): HasMany
    {
        return $this->hasMany(MdBundle::class);
    }

    public function sourceDrafts(): HasMany
    {
        return $this->hasMany(SourceDraft::class, 'knowledge_base_id');
    }

    public function contentSources(): BelongsToMany
    {
        return $this->belongsToMany(ContentSource::class, 'notebook_content_sources')
            ->using(NotebookContentSource::class)
            ->withPivot('added_at');
    }

    public function isConsolidating()
    {
        return $this->mdBundles()->where('is_consolidating', true)->exists();
    }

    public function nlm(?TechAccount $account = null)
    {
        return new NotebookNLMDecorator($this, $account ? $account->service() : null);
    }
}
