<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\NotebookLM\DTOs\AskResultDTO;
use App\Domain\NotebookLM\DTOs\ChatReferenceDTO;

class AskResultDTOFactory
{
    public static function make(array $attributes = []): AskResultDTO
    {
        return AskResultDTO::from(array_merge([
            'answer' => fake()->paragraph(),
            'conversation_id' => fake()->uuid(),
            'turn_number' => 1,
            'is_follow_up' => false,
            'references' => ChatReferenceDTO::collect([
                ChatReferenceDTOFactory::make()->toArray(),
                ChatReferenceDTOFactory::make()->toArray(),
            ]),
        ], $attributes));
    }
}
