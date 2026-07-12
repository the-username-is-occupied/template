<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\NotebookLM\DTOs\NotebookDescriptionDTO;
use App\Domain\NotebookLM\NotebookNLMDecorator;
use App\Services\AskService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

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
        'slug',
        'system_prompt',
        'status',
        'description',
    ];

    protected function casts(): array
    {
        return [
            'user_id' => 'string',
            'tech_account_id' => 'string',
            'nlm_notebook_id' => 'string',
            'title' => 'string',
            'slug' => 'string',
            'system_prompt' => 'string',
            'status' => 'string',
            'description' => 'array',
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

    public function chats()
    {
        return $this->hasMany(Chat::class);
    }

    public function scopeSlug(Builder $q, string $slug): Builder
    {
        return $q->where('slug', $slug);
    }

    public function scopeHasSlug(Builder $q): Builder
    {
        return $q->whereNotNull('slug');
    }

    public function nlm(?TechAccount $account = null)
    {
        return new NotebookNLMDecorator($this, $account ? $account->service() : null);
    }

    public function ask(string $q)
    {
        return app()->make(AskService::class)->ask($this, $q);
    }

    public function getDescriptionAttribute($value)
    {
        return NotebookDescriptionDTO::from(json_decode($value, true));
    }

    public function makeSharable()
    {
        return $this->nlm()->setPublic();
    }

    public static function updateDesc()
    {
        static::query()->hasSlug()->with('techAccount')->chunk(10, function ($notebooks) {
            foreach ($notebooks as $notebook) {
                $notebook->setDescription();
            }
        });
    }

    public function setDescription()
    {
        $dto = $this->nlm()->getNotebookDescription();
        $this->description = $dto->toArray();
        $this->save();
    }

    public function setSystemPrompt(?string $user_prompt = null): void
    {
        $this->system_prompt = config('notebook-lm.system_prompt');
        if ($user_prompt) {
            $this->system_prompt .= "\n\n Пользовательский промпт: \n".$user_prompt;
        }
        $this->save();

        $this->nlm()->configure($this->system_prompt);
    }

    public function clean()
    {
        $this->techAccount()->first()->service()->cleanupNotebookSources($this->nlm_notebook_id);

        // Получить все bundles для этого notebook
        $bundles = $this->mdBundles()->get();

        if ($bundles->isEmpty()) {
            return;
        }

        // Удалить файлы бандлов
        foreach ($bundles as $bundle) {
            $bundle->bundleItems()->delete();
            if ($bundle->file_path && Storage::disk('bundles')->exists($bundle->file_path)) {
                Storage::disk('bundles')->delete($bundle->file_path);
            }

            $bundle->delete();
        }

        foreach ($this->contentSources()->get() as $contentSource) {
            foreach ($contentSource->originalItems()->bundled()->cursor() as $originalItem) {
                $originalItem->bundles()->detach();
            }
        }
    }
}
