<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\NotebookLM\DTOs\SourceDTO;

class SourceDTOFactory
{
    public static function make(array $attributes = []): SourceDTO
    {
        return SourceDTO::from(array_merge([
            'id' => fake()->uuid(),
            'title' => fake()->sentence(4, false),
            'url' => fake()->optional()->url(),
            'created_at' => fake()->dateTime()->format('Y-m-d H:i:s'),
            'status' => 'ready',
            'kind' => fake()->randomElement(['url', 'text', 'file']),
            'is_ready' => true,
            'is_processing' => false,
            'is_error' => false,
        ], $attributes));
    }
}
