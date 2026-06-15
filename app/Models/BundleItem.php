<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\BundleItemFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BundleItem extends Model
{
    /** @use HasFactory<BundleItemFactory> */
    use HasFactory;
    use HasUuids;

    protected $table = 'bundle_items';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = [
        'bundle_id',
        'original_item_id',
        'position',
    ];

    protected function casts(): array
    {
        return [
            'bundle_id' => 'string',
            'original_item_id' => 'string',
            'position' => 'integer',
        ];
    }

    public function bundle(): BelongsTo
    {
        return $this->belongsTo(MdBundle::class, 'bundle_id');
    }

    public function originalItem(): BelongsTo
    {
        return $this->belongsTo(OriginalItem::class);
    }
}
