<?php

declare(strict_types=1);

namespace App\Domain\NotebookLM\DTOs;

use App\Domain\Citations\DTOs\ResolvedAskResultDTO;
use App\Services\CitationResolver2 as CitationResolver;
use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Attributes\MapInputName;
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
        // /** @var NextSuggestedTopicDTO[] */
        // public DataCollection $next_steps = new DataCollection(NextSuggestedTopicDTO::class, []),
        #[MapInputName('next_steps')]
        #[DataCollectionOf(NextSuggestedTopicDTO::class)]
        public DataCollection $suggested,
    ) {}

    public function resolve(): ResolvedAskResultDTO
    {
        return app()->make(CitationResolver::class)->resolve($this);
    }
}
