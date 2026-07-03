<?php

declare(strict_types=1);

namespace App\Domain\NotebookLM\DTOs;

use App\Services\CitationResolver as CitationResolver;
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

    public function extractSuggested(): void
    {
        [$cleanedAnswer, $suggestedTopics] = $this->extractSuggestedQuestions($this->answer);
        $this->answer = $cleanedAnswer;
        $this->suggested = new DataCollection(SuggestedTopicDTO::class, $suggestedTopics);
    }

    /**
     * Extract suggested questions from answer text.
     *
     * Looks for "Вопросы" section and parses numbered questions.
     * Returns cleaned answer (without questions section) and array of SuggestedTopicDTO.
     *
     * @return array{string, SuggestedTopicDTO[]}
     */
    private function extractSuggestedQuestions(string $answer): array
    {
        // Check if answer contains "Вопросы"
        $position = mb_strpos($answer, 'Вопросы');

        if ($position === false) {
            return [$answer, []];
        }

        // Split answer at "Вопросы"
        $cleanedAnswer = mb_substr($answer, 0, $position);
        $questionsSection = mb_substr($answer, $position);

        // Parse numbered questions from the questions section
        // Match patterns like "1. Question text" or "  2. Question text"
        preg_match_all('/^\s*\d+\.\s*.+$/m', $questionsSection, $matches);

        $suggestedTopics = [];
        if (! empty($matches[0])) {
            foreach ($matches[0] as $questionText) {
                $questionText = trim($questionText);
                $suggestedTopics[] = new SuggestedTopicDTO(
                    question: $questionText,
                    prompt: ''
                );
            }
        }

        return [trim($cleanedAnswer), $suggestedTopics];
    }
}
