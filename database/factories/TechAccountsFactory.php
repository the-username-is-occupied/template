<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\TechAccountPoolType;
use App\Enums\TechAccountStatus;
use App\Models\TechAccounts;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TechAccounts>
 */
class TechAccountsFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'pool_type' => fake()->randomElement(TechAccountPoolType::cases()),
            'status' => fake()->randomElement(TechAccountStatus::cases()),
            'cookie_path' => null,
            'proxy_host' => fake()->optional()->domainName(),
            'notebooks_count' => fake()->numberBetween(0, 20),
            'chats_today' => fake()->numberBetween(0, 50),
            'chats_reset_at' => now()->subHour(),
            'last_used_at' => now()->subMinutes(fake()->numberBetween(1, 300)),
        ];
    }
}
