<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\Citations\DTOs\ResolvedAskResultDTO;
use App\Domain\NotebookLM\DTOs\SuggestedTopicDTO;
use App\Domain\NotebookLM\NotebookLMService;
use App\Exceptions\DailyLimitExceededException;
use App\Models\Chat;
use App\Models\ChatMessage;
use App\Models\Notebook;
use App\Models\TechAccountUsage;
use Illuminate\Support\Facades\DB;
use Spatie\LaravelData\DataCollection;

class AskService
{
    public function __construct(
        private readonly NotebookLMService $notebookLMService,
        private readonly AccountService $accountService,
    ) {}

    /**
     * Extract suggested questions from answer text.
     *
     * Looks for "Вопросы" section and parses numbered questions.
     * Returns cleaned answer (without questions section) and array of SuggestedTopicDTO.
     *
     * @param  string  $answer
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
        if (!empty($matches[0])) {
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

    /**
     * Ask a question to a notebook.
     *
     * @param  Notebook  $notebook  The notebook to ask
     * @param  string  $question  The question to ask
     * @param  Chat|null  $chat  Existing chat or null to create new
     *
     * @throws DailyLimitExceededException when daily limit exceeded
     */
    public function ask(Notebook $notebook, string $question, ?Chat $chat = null): ChatMessage
    {
        if ($notebook->isConsolidating()) {
            throw new \RuntimeException('Notebook is currently being optimized. Please wait a moment and try again.');
        }

        // Step 1: Get account for ask
        $account = $this->accountService->getAccountForAsk();

        // Step 2: Create or get chat
        if (! $chat) {
            $chat = Chat::create([
                'user_id' => $notebook->user_id,
                'notebook_id' => $notebook->id,
            ]);
        }

        // Step 3: Save user message
        ChatMessage::create([
            'chat_id' => $chat->id,
            'role' => 'user',
            'content' => $question,
        ]);

        try {
            // Step 4: Call NotebookLM
            $dto = $this->notebookLMService->askQuestion(
                $account->id,
                $notebook->nlm_notebook_id,
                $question
            );

            // Step 4.5: Extract suggested questions from answer
            $answer = $dto->answer;
            [$cleanedAnswer, $suggestedTopics] = $this->extractSuggestedQuestions($answer);
            $dto->answer = $cleanedAnswer;
            $dto->suggested = new DataCollection(SuggestedTopicDTO::class, $suggestedTopics);

            // Step 5: Increment usage
            TechAccountUsage::upsert(
                [['tech_account_id' => $account->id, 'date' => today(), 'count' => 1]],
                ['tech_account_id', 'date'],
                ['count' => DB::raw('tech_account_usages.count + 1')]
            );

            // Step 6: Resolve citations
            $resolved = $dto->resolve();

            // Step 7: Save assistant message (success)
            $message = ChatMessage::create([
                'chat_id' => $chat->id,
                'tech_account_id' => $account->id,
                'role' => 'assistant',
                'result' => $dto->toArray(),
                'is_success' => true,
            ]);

            // Step 8: Return resolved result
            return $message;

        } catch (\Throwable $e) {
            // Save failed attempt
            ChatMessage::create([
                'chat_id' => $chat->id,
                'tech_account_id' => $account->id,
                'role' => 'assistant',
                'content' => null,
                'result' => null,
                'is_success' => false,
            ]);

            throw $e;
        }
    }
}
