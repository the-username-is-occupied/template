<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\NotebookLM\DTOs\SuggestedTopicDTO;

class SuggestedTopicDTOFactory
{
    public static function make(array $attributes = []): SuggestedTopicDTO
    {
        return SuggestedTopicDTO::from(array_merge([
            'question' => fake()->sentence().'?',
            'prompt' => fake()->sentence(),
        ], $attributes));
    }
}
