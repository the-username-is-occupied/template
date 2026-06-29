<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\Citations\DTOs\ResolvedAskResultDTO;
use App\Domain\NotebookLM\NotebookLMService;
use App\Exceptions\DailyLimitExceededException;
use App\Models\Chat;
use App\Models\ChatMessage;
use App\Models\Notebook;
use App\Models\TechAccountUsage;
use Illuminate\Support\Facades\DB;

class AskService
{
    public function __construct(
        private readonly NotebookLMService $notebookLMService,
        private readonly AccountService $accountService,
    ) {}

    /**
     * Ask a question to a notebook.
     *
     * @param  Notebook  $notebook  The notebook to ask
     * @param  string  $question  The question to ask
     * @param  Chat|null  $chat  Existing chat or null to create new
     *
     * @throws DailyLimitExceededException when daily limit exceeded
     */
    public function ask(Notebook $notebook, string $question, ?Chat $chat = null): ResolvedAskResultDTO
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

            // Step 5: Increment usage
            TechAccountUsage::upsert(
                [['tech_account_id' => $account->id, 'date' => today(), 'count' => 1]],
                ['tech_account_id', 'date'],
                ['count' => DB::raw('tech_account_usages.count + 1')]
            );

            // Step 6: Resolve citations
            $resolved = $dto->resolve();

            // Step 7: Save assistant message (success)
            ChatMessage::create([
                'chat_id' => $chat->id,
                'tech_account_id' => $account->id,
                'role' => 'assistant',
                'result' => $dto->toArray(),
                'is_success' => true,
            ]);

            // Step 8: Return resolved result
            return $resolved;

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
