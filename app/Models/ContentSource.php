<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\DiscoveryMethod;
use App\Enums\ExtractionStatus;
use App\Enums\ReviewStatus;
use App\Enums\SourceType;
use Database\Factories\ContentSourceFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ContentSource extends Model
{
    /** @use HasFactory<ContentSourceFactory> */
    use HasFactory;

    use HasUuids;

    protected $table = 'content_sources';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'user_id',
        'type',
        'url',
        'file_ref',
        'title',
        'auto_update',
        'extraction_status',
        'nlm_temp_source_id',
        'parent_source_id',
        'parent_item_id',
        'discovery_method',
        'review_status',
        'last_fetched_id',
        'metadata',
        'error_message',
        'error_code',
    ];

    protected function casts(): array
    {
        return [
            'type' => SourceType::class,
            'auto_update' => 'boolean',
            'extraction_status' => ExtractionStatus::class,
            'discovery_method' => DiscoveryMethod::class,
            'review_status' => ReviewStatus::class,
            'metadata' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function parentSource(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_source_id');
    }

    public function parentItem(): BelongsTo
    {
        return $this->belongsTo(OriginalItem::class, 'parent_item_id');
    }

    public function sourceDrafts(): HasMany
    {
        return $this->hasMany(SourceDraft::class);
    }

    public function originalItems(): HasMany
    {
        return $this->hasMany(OriginalItem::class);
    }

    public function notebooks(): BelongsToMany
    {
        return $this->belongsToMany(Notebook::class, 'notebook_content_sources')
            ->using(NotebookContentSource::class)
            ->withPivot('added_at');
    }

    public function scopePendingReview(Builder $query)
    {
        return $query->where('review_status', ReviewStatus::PendingReview);
    }

    public function scopeAutoUpdate(Builder $query)
    {
        return $query->where('auto_update', true);
    }

    public function scopeApproved(Builder $query)
    {
        return $query->where('review_status', ReviewStatus::Approved);
    }
}
