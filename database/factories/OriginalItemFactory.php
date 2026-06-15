<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\ContentSource;
use App\Models\OriginalItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OriginalItem>
 */
class OriginalItemFactory extends Factory
{
    protected $model = OriginalItem::class;

    public function definition(): array
    {
        return [
            'content_source_id' => ContentSource::factory(),
            'title' => fake()->sentence(6),
            'full_text' => fake()->paragraphs(fake()->numberBetween(3, 8), true),
            'source_url' => fake()->url(),
            'parent_item_id' => null,
            'published_at' => fake()->optional()->dateTimeBetween('-1 years', 'now'),
            'word_count' => fake()->numberBetween(50, 1200),
            'metadata' => ['source_type' => 'text'],
        ];
    }
}
