<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\TokenUsageLog;
use App\Models\UserSpace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TokenUsageLog>
 */
class TokenUsageLogFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_space_id' => UserSpace::factory(),
            'operation_type' => $this->faker->randomElement(['indexing', 'query']),
            'model_name' => 'gpt-4o-mini',
            'prompt_tokens' => $this->faker->numberBetween(10, 2000),
            'completion_tokens' => $this->faker->numberBetween(0, 1000),
            'estimated_cost_usd' => $this->faker->randomFloat(8, 0, 0.01),
        ];
    }
}
