<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\NotebookLM\NotebookLMService;
use App\Exceptions\DailyLimitExceededException;
use App\Models\Chat;
use App\Models\ChatMessage;
use App\Models\Notebook;
use App\Models\User;

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
     * @param  User  $user  The user asking the question
     * @param  ChatMessage|null  $followUpMessage  Follow-up message or null
     *
     * @throws DailyLimitExceededException when daily limit exceeded
     */
    public function ask(Notebook $notebook, string $question, User $user, ?ChatMessage $followUpMessage = null): ChatMessage
    {
        if ($notebook->isConsolidating()) {
            throw new \RuntimeException('Notebook is currently being optimized. Please wait a moment and try again.');
        }

        // Step 1: Get account for ask
        $account = $this->accountService->getAccountForAsk();

        // Step 2: Create or get chat
        $chat = Chat::create([
            'user_id' => $user->id,
            'notebook_id' => $notebook->id,
        ]);

        // Step 3: Save user message
        $msg = ChatMessage::create([
            'chat_id' => $chat->id,
            'content' => $question,
            'tech_account_id' => $account->id,
            'follow_up_id' => $followUpMessage?->id,
        ]);

        try {
            $dto = $this->notebookLMService->askQuestion(
                $account->id,
                $notebook->nlm_notebook_id,
                $question
            );

            $dto->extractSuggested();

            $msg->update([
                'result' => $dto->toArray(),
                'is_success' => true,
            ]);

            return $msg;

        } catch (\Throwable $e) {
            $msg->update([
                'is_success' => false,
            ]);
            throw $e;
        } finally {
            $this->accountService->incrementAskCount($account);
        }
    }
}
