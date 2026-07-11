<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\SourceType;
use Carbon\CarbonInterval;
use Database\Factories\OriginalItemFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
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
    ];

    protected function casts(): array
    {
        return [
            'content_source_id' => 'string',
            'parent_item_id' => 'string',
            'published_at' => 'datetime',
            'word_count' => 'integer',
            'metadata' => 'array',
            'created_at' => 'datetime',
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

    public function bundles(): BelongsToMany
    {
        return $this->belongsToMany(MdBundle::class, 'bundle_items', 'original_item_id', 'bundle_id');
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
        return $query->whereDoesntHave('bundles');
    }

    public function scopeBundled($query)
    {
        return $query->whereHas('bundles');
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

    public function getTitle()
    {

        return match ($this->contentSource->type) {
            SourceType::TelegramChannel => $this->source_url ? 'Пост #'.basename($this->source_url) : $this->title,
            SourceType::YoutubeChannel, SourceType::YoutubeVideo => $this->title ?? 'Видео',
            default => $this->title
        };
    }

    public function getType()
    {
        return match ($this->contentSource->type) {
            SourceType::TelegramChannel => 'telegram_post',
            SourceType::YoutubeChannel, SourceType::YoutubeVideo => 'youtube_video',
            default => ''
        };
    }

    public function getMetaArray()
    {
        return (match ($this->contentSource->type) {
            SourceType::TelegramChannel => collect($this->metadata)
                ->map(function ($value, $key) {
                    if ($key === 'reactions') {
                        return collect($value)
                            ->map(fn ($reaction) => "{$reaction['emoji']}({$reaction['count']})")
                            ->implode(', ');
                    }

                    return $value;
                }),
            SourceType::YoutubeChannel, SourceType::YoutubeVideo => collect($this->metadata['youtube'] ?? [])
                ->filter(fn ($i, $k) => is_null($k) || ! in_array($k, ['url', 'title', 'tags', 'publishedAt', 'handle', 'videoId', 'channelId', 'channelTitle'])),
            default => collect([])
        })->filter(fn ($i, $k) => $k != 'type')->merge(['type' => $this->getType()]);
    }

    /**
     * Конвертирует ISO 8601 duration (например "PT1H2M3S") в секунды.
     */
    public static function toSeconds(?string $isoDuration): ?int
    {
        if (! $isoDuration) {
            return null;
        }

        try {
            $interval = CarbonInterval::make($isoDuration);

            return $interval?->totalSeconds !== null
                ? (int) $interval->totalSeconds
                : null;
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * Конвертирует ISO 8601 duration в человекочитаемый формат: "1:02:03" или "2:03".
     */
    public static function toHuman(?string $isoDuration): ?string
    {
        $seconds = self::toSeconds($isoDuration);

        if ($seconds === null) {
            return null;
        }

        $hours = intdiv($seconds, 3600);
        $minutes = intdiv($seconds % 3600, 60);
        $secs = $seconds % 60;

        return $hours > 0
            ? sprintf('%d:%02d:%02d', $hours, $minutes, $secs)
            : sprintf('%d:%02d', $minutes, $secs);
    }
}
