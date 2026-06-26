<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Notebook;
use App\Models\TgUserSession;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TgUserSession>
 */
class TgUserSessionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tg_user_id' => fake()->unique()->randomNumber(9),
            'active_base_id' => fake()->optional()->randomElement([
                Notebook::factory(),
                null,
            ]),
        ];
    }

    public function withActiveBase(): static
    {
        return $this->state(fn () => [
            'active_base_id' => Notebook::factory(),
        ]);
    }

    public function withoutActiveBase(): static
    {
        return $this->state(fn () => [
            'active_base_id' => null,
        ]);
    }
}
