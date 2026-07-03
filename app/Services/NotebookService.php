<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\NotebookLM\NotebookLMService;
use App\Models\Notebook;
use App\Models\TechAccount;
use App\Models\User;

class NotebookService{

    public function create(string $title, TechAccount $techAccount, User $user): Notebook
    {
        $notebookLMService = app(NotebookLMService::class);
       
        $notebookDTO = $notebookLMService->createNotebook(
            $techAccount->id,
            $title
        );

        $nlmNotebookId = $notebookDTO->id;

        $notebook = Notebook::create([
            'user_id' => $user->id,
            'tech_account_id' => $techAccount->id,
            'nlm_notebook_id' => $nlmNotebookId,
            'title' => $title,
        ]);

        $notebook->setSystemPrompt();

        return $notebook;
    }
}