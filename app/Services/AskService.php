<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\NotebookLM\DTOs\AskResultDTO;
use App\Domain\NotebookLM\NotebookLMService;
use App\Models\Notebook;

class AskService
{
    public function __construct(
        private readonly NotebookLMService $notebookLMService,
    ) {}

    /**
     * Ask a question to a notebook.
     */
    public function ask(Notebook $notebook, string $question): AskResultDTO
    {

        if ($notebook->isConsolidating()) {

            throw new \RuntimeException('Notebook is currently being optimized. Please wait a moment and try again.');
        }

        return $this->notebookLMService->askQuestion(
            $notebook->account_id,
            $notebook->nlm_notebook_id,
            $question
        );
    }
}
