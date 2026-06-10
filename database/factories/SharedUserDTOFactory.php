<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\NotebookLM\DTOs\SharedUserDTO;

class SharedUserDTOFactory
{
    public static function make(array $attributes = []): SharedUserDTO
    {
        return SharedUserDTO::from(array_merge([
            'email' => fake()->safeEmail(),
            'permission' => fake()->randomElement(['viewer', 'editor', 'owner']),
            'display_name' => fake()->name(),
            'avatar_url' => null,
        ], $attributes));
    }
}
