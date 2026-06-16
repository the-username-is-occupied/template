<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\OriginalItemFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

class OriginalItem extends Model
{
    /** @use HasFactory<OriginalItemFactory> */
    use HasFactory;

    use HasUuids;

    protected $table = 'original_items';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = [
        'content_source_id',
        'title',
        'full_text',
        'source_url',
        'parent_item_id',
        'published_at',
        'word_count',
        'metadata',
        'md_bundle_id',
    ];

    protected function casts(): array
    {
        return [
            'content_source_id' => 'string',
            'parent_item_id' => 'string',
            'md_bundle_id' => 'string',
            'published_at' => 'datetime',
            'word_count' => 'integer',
            'metadata' => 'array',
        ];
    }

    public function contentSource(): BelongsTo
    {
        return $this->belongsTo(ContentSource::class);
    }

    public function parentItem(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_item_id');
    }

    public function bundle(): BelongsTo
    {
        return $this->belongsTo(MdBundle::class, 'md_bundle_id');
    }

    public function childItems(): HasMany
    {
        return $this->hasMany(self::class, 'parent_item_id');
    }

    public function bundleItems(): HasMany
    {
        return $this->hasMany(BundleItem::class);
    }

    public function scopeUnbundled($query)
    {
        return $query->whereNull('md_bundle_id');
    }

    /**
     * Scope to get items that need bundling with their notebook IDs.
     */
    public function scopeForNotebooksWithUnbundledItems($query)
    {
        return $query->unbundled()
            ->join('content_sources', 'original_items.content_source_id', '=', 'content_sources.id')
            ->join('notebook_content_sources', 'content_sources.id', '=', 'notebook_content_sources.content_source_id');
    }

    /**
     * Get distinct notebook IDs that have unbundled items.
     */
    public static function getNotebookIdsWithUnbundledItems(): Collection
    {
        return static::forNotebooksWithUnbundledItems()
            ->distinct()
            ->pluck('notebook_content_sources.notebook_id');
    }
}
