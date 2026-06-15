<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Notebook;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Notebook>
 */
class NotebookFactory extends Factory
{
    protected $model = Notebook::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'nlm_notebook_id' => fake()->uuid(),
            'title' => fake()->sentence(3),
            'system_prompt' => fake()->optional()->sentence(),
            'status' => 'active',
        ];
    }
}
