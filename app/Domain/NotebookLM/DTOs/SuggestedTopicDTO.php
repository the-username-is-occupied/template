<?php

declare(strict_types=1);

namespace App\Domain\NotebookLM\DTOs;

use Spatie\LaravelData\Data;

class SuggestedTopicDTO extends Data
{
    public function __construct(
        public string $question,
        public string $prompt,
    ) {}
}
