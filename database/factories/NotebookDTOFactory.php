<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\NotebookLM\DTOs\NotebookDTO;

class NotebookDTOFactory
{
    public static function make(array $attributes = []): NotebookDTO
    {
        return NotebookDTO::from(array_merge([
            'id' => fake()->uuid(),
            'title' => fake()->sentence(4, false),
            'created_at' => fake()->dateTime()->format('Y-m-d H:i:s'),
            'sources_count' => fake()->numberBetween(0, 10),
            'is_owner' => true,
        ], $attributes));
    }
}
