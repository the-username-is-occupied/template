<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\NotebookLM\DTOs\NotebookMetadataSourceDTO;

class NotebookMetadataSourceDTOFactory
{
    public static function make(array $attributes = []): NotebookMetadataSourceDTO
    {
        return NotebookMetadataSourceDTO::from(array_merge([
            'id' => fake()->uuid(),
            'kind' => fake()->randomElement(['url', 'text', 'file']),
            'title' => fake()->sentence(4, false),
            'url' => fake()->optional()->url(),
        ], $attributes));
    }
}
