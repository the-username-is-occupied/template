<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\BundleItem;
use App\Models\MdBundle;
use App\Models\OriginalItem;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class BundleItemsService
{
    /**
     * Create bundle_items records for the given items in a bundle.
     *
     * @param  Collection<array-key, OriginalItem>  $items
     */
    public function attachItems(MdBundle $bundle, Collection $items): void
    {
        $data = $items->values()->map(fn (OriginalItem $item, int $index) => [
            'id' => (string) Str::orderedUuid(),
            'bundle_id' => $bundle->id,
            'original_item_id' => $item->id,
            'position' => $index + 1,
        ])->all();

        foreach (array_chunk($data, 500) as $chunk) {
            BundleItem::insert($chunk);
        }
    }
}
