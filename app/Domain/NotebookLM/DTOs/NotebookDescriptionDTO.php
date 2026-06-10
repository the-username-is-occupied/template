<?php

declare(strict_types=1);

namespace App\Domain\NotebookLM\DTOs;

use Spatie\LaravelData\Data;

class NotebookDescriptionDTO extends Data
{
    /** @var SuggestedTopicDTO[] */
    public array $suggested_topics = [];

    public function __construct(
        public string $summary,
        array $suggested_topics = [],
    ) {
        $this->suggested_topics = $suggested_topics;
    }
}
