<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\NotebookLM\DTOs\NotebookDescriptionDTO;

class NotebookDescriptionDTOFactory
{
    public static function make(array $attributes = []): NotebookDescriptionDTO
    {
        return NotebookDescriptionDTO::from(array_merge([
            'summary' => fake()->paragraph(),
            'suggested_topics' => [
                SuggestedTopicDTOFactory::make(),
                SuggestedTopicDTOFactory::make(),
            ],
        ], $attributes));
    }
}
