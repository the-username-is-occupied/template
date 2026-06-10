<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\NotebookLM\DTOs\ChatReferenceDTO;

class ChatReferenceDTOFactory
{
    public static function make(array $attributes = []): ChatReferenceDTO
    {
        return ChatReferenceDTO::from(array_merge([
            'source_id' => fake()->uuid(),
            'citation_number' => fake()->numberBetween(1, 20),
            'cited_text' => fake()->sentence(),
            'start_char' => fake()->numberBetween(0, 500),
            'end_char' => fake()->numberBetween(500, 1000),
            'chunk_id' => null,
        ], $attributes));
    }
}
