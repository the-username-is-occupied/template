<?php

declare(strict_types=1);

namespace App\Domain\NotebookLM\DTOs;

use Spatie\LaravelData\Data;

class NextSuggestedTopicDTO extends Data
{
    public function __construct(
        public string $question,
        public string|int|null $type_code = null,
    ) {}
}
