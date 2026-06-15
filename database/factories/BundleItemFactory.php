<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\BundleItem;
use App\Models\MdBundle;
use App\Models\OriginalItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BundleItem>
 */
class BundleItemFactory extends Factory
{
    protected $model = BundleItem::class;

    public function definition(): array
    {
        return [
            'bundle_id' => MdBundle::factory(),
            'original_item_id' => OriginalItem::factory(),
            'position' => fake()->numberBetween(1, 10),
        ];
    }
}
