<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\MdBundleStatus;
use App\Enums\MdBundleType;
use App\Models\MdBundle;
use App\Models\Notebook;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MdBundle>
 */
class MdBundleFactory extends Factory
{
    protected $model = MdBundle::class;

    public function definition(): array
    {
        return [
            'notebook_id' => Notebook::factory(),
            'type' => fake()->randomElement(MdBundleType::cases()),
            'file_path' => 'bundles/'.fake()->uuid().'.md',
            'word_count' => fake()->numberBetween(1000, 10000),
            'status' => fake()->randomElement(MdBundleStatus::cases()),
            'nlm_source_id' => fake()->optional()->uuid(),
            'is_consolidating' => false,
            'error_code' => null,
        ];
    }

    public function activeDelta(): static
    {
        return $this->state(['type' => MdBundleType::ActiveDelta]);
    }

    public function activeQuarter(): static
    {
        return $this->state(['type' => MdBundleType::ActiveQuarter]);
    }

    public function frozenFull(): static
    {
        return $this->state(['type' => MdBundleType::FrozenFull]);
    }
}
