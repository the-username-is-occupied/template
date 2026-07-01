<?php

declare(strict_types=1);

namespace App\Domain\Citations\DTOs;

use App\Domain\NotebookLM\DTOs\SuggestedTopicDTO;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\DataCollection;

class ResolvedAskResultDTO extends Data
{
    public function __construct(
        public string $answer,
        /** @var CitationData[] */
        public DataCollection $citations,
        /** @var SuggestedTopicDTO[] */
        public DataCollection $suggested = new DataCollection(SuggestedTopicDTO::class, []),
    ) {}

    public function getUrls(): array
    {
        return collect($this->citations)
            ->pluck('source_url')
            ->toArray();
    }

    public function getCitationTexts(): array
    {
        return collect($this->citations)
            ->pluck('cited_text_clean', 'citation_number')
            ->toArray();
    }

    public function getCitationLinks(): array
    {
        return collect($this->citations)
            ->pluck('source_url', 'citation_number')
            ->toArray();
    }

    public function getQuestions(): array
    {
        return collect($this->suggested)->map->question->toArray();
    }
}
