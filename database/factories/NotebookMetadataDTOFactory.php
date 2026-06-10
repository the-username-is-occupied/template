<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\NotebookLM\DTOs\NotebookMetadataDTO;

class NotebookMetadataDTOFactory
{
    public static function make(array $attributes = []): NotebookMetadataDTO
    {
        return NotebookMetadataDTO::from(array_merge([
            'notebook' => NotebookDTOFactory::make(),
            'sources' => [
                NotebookMetadataSourceDTOFactory::make(),
                NotebookMetadataSourceDTOFactory::make(),
            ],
        ], $attributes));
    }
}
