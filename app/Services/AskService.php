<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\Citations\DTOs\ResolvedAskResultDTO;
use App\Domain\NotebookLM\DTOs\AskResultDTO;
use App\Domain\NotebookLM\NotebookLMService;
use App\Models\Notebook;
use App\Services\CitationResolver;

class AskService
{
    public function __construct(
        private readonly NotebookLMService $notebookLMService,
    ) {}

    /**
     * Ask a question to a notebook.
     */
    public function ask(Notebook $notebook, string $question): ResolvedAskResultDTO
    {

        if ($notebook->isConsolidating()) {

            throw new \RuntimeException('Notebook is currently being optimized. Please wait a moment and try again.');
        }

        $dto = $this->notebookLMService->askQuestion(
            $notebook->tech_account_id,
            $notebook->nlm_notebook_id,
            $question
        );

        return app()->make(CitationResolver::class)->resolve($dto);

    }
}
