<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\BundleItem;
use App\Models\MdBundle;
use App\Models\OriginalItem;
use Illuminate\Support\Collection;

class BundleItemsService
{
    /**
     * Create bundle_items records for the given items in a bundle.
     *
     * @param  Collection<array-key, OriginalItem>  $items
     * @return Collection<array-key, BundleItem>
     */
    public function attachItems(MdBundle $bundle, Collection $items): Collection
    {
        $bundleItems = collect();

        $items->each(function (OriginalItem $item, int $index) use ($bundle, $bundleItems): void {
            $bundleItem = BundleItem::create([
                'bundle_id' => $bundle->id,
                'original_item_id' => $item->id,
                'position' => $index + 1,
            ]);

            // $item->update(['md_bundle_id' => $bundle->id]);

            $bundleItems->push($bundleItem);
        });

        return $bundleItems;
    }
}
