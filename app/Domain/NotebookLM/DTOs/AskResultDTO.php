<?php

declare(strict_types=1);

namespace App\Domain\NotebookLM\DTOs;

use App\Services\CitationResolver;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\DataCollection;

class AskResultDTO extends Data
{
    public function __construct(
        public string $answer,
        public string $conversation_id,
        public int $turn_number,
        public bool $is_follow_up,
        /** @var ChatReferenceDTO[] */
        public DataCollection $references,
        /** @var SuggestedTopicDTO[] */
        public DataCollection $suggested = new DataCollection(SuggestedTopicDTO::class, []),
    ) {}

    public function resolve()
    {
        return app()->make(CitationResolver::class)->resolve($this);
    }
}
