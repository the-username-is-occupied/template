<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\MdBundleStatus;
use App\Enums\MdBundleType;
use Database\Factories\MdBundleFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MdBundle extends Model
{
    /** @use HasFactory<MdBundleFactory> */
    use HasFactory;
    use HasUuids;

    protected $table = 'md_bundles';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'notebook_id',
        'type',
        'file_path',
        'word_count',
        'status',
        'nlm_source_id',
        'is_consolidating',
        'error_code',
    ];

    protected function casts(): array
    {
        return [
            'notebook_id' => 'string',
            'type' => MdBundleType::class,
            'word_count' => 'integer',
            'status' => MdBundleStatus::class,
            'is_consolidating' => 'boolean',
        ];
    }

    public function notebook(): BelongsTo
    {
        return $this->belongsTo(Notebook::class);
    }

    public function bundleItems(): HasMany
    {
        return $this->hasMany(BundleItem::class, 'bundle_id');
    }

    public function scopeActive($query)
    {
        return $query->whereIn('type', [MdBundleType::ActiveDelta, MdBundleType::ActiveQuarter]);
    }

    public function scopeConsolidating($query)
    {
        return $query->where('is_consolidating', true);
    }

    public function scopeActiveDelta($query)
    {
        return $query->where('type', MdBundleType::ActiveDelta);
    }

    public function scopeActiveQuarter($query)
    {
        return $query->where('type', MdBundleType::ActiveQuarter);
    }

    public function scopeFrozenQuarter($query)
    {
        return $query->where('type', MdBundleType::FrozenQuarter);
    }

    public function scopeFrozenHalf($query)
    {
        return $query->where('type', MdBundleType::FrozenHalf);
    }

    public function scopeFrozenFull($query)
    {
        return $query->where('type', MdBundleType::FrozenFull);
    }
}
