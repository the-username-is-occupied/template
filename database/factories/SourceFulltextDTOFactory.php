<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\NotebookLM\DTOs\SourceFulltextDTO;

class SourceFulltextDTOFactory
{
    public static function make(array $attributes = []): SourceFulltextDTO
    {
        return SourceFulltextDTO::from(array_merge([
            'source_id' => fake()->uuid(),
            'title' => fake()->sentence(4, false),
            'content' => fake()->paragraphs(3, true),
            'url' => fake()->optional()->url(),
            'char_count' => fake()->numberBetween(200, 5000),
            'format' => 'markdown',
        ], $attributes));
    }
}
