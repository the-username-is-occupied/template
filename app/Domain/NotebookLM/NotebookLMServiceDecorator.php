<?php

declare(strict_types=1);

namespace App\Domain\NotebookLM;

use App\Domain\NotebookLM\DTOs\AskResultDTO;
use App\Domain\NotebookLM\DTOs\NotebookDescriptionDTO;
use App\Domain\NotebookLM\DTOs\NotebookDTO;
use App\Domain\NotebookLM\DTOs\NotebookMetadataDTO;
use App\Domain\NotebookLM\DTOs\SettingsDTO;
use App\Domain\NotebookLM\DTOs\ShareStatusDTO;
use App\Domain\NotebookLM\DTOs\SourceDTO;
use App\Domain\NotebookLM\DTOs\SourceFulltextDTO;
use App\Models\TechAccount;

/**
 * Decorator for NotebookLMService that automatically uses a specific TechAccount.
 *
 * This decorator wraps NotebookLMService and automatically injects the
 * TechAccount ID into all method calls, eliminating the need to pass
 * the account ID manually.
 */
class NotebookLMServiceDecorator
{
    protected NotebookLMService $service;

    protected TechAccount $account;

    public function __construct(TechAccount $account, ?NotebookLMService $service = null)
    {
        $this->account = $account;
        $this->service = $service ?? new NotebookLMService;
    }

    // =========================================================================
    // Notebooks
    // =========================================================================

    /**
     * List all notebooks for the account.
     *
     * @return NotebookDTO[]
     */
    public function listNotebooks(): array
    {
        return $this->service->listNotebooks($this->account->id);
    }

    /**
     * Create a new notebook for the account.
     */
    public function createNotebook(string $title): NotebookDTO
    {
        return $this->service->createNotebook($this->account->id, $title);
    }

    /**
     * Get notebook details.
     */
    public function getNotebook(string $notebookId): NotebookDTO
    {
        return $this->service->getNotebook($this->account->id, $notebookId);
    }

    /**
     * Get AI-generated description for a notebook.
     */
    public function getNotebookDescription(string $notebookId): NotebookDescriptionDTO
    {
        return $this->service->getNotebookDescription($this->account->id, $notebookId);
    }

    /**
     * Rename a notebook.
     */
    public function renameNotebook(string $notebookId, string $title): NotebookDTO
    {
        return $this->service->renameNotebook($this->account->id, $notebookId, $title);
    }

    /**
     * Delete a notebook.
     */
    public function deleteNotebook(string $notebookId): bool
    {
        return $this->service->deleteNotebook($this->account->id, $notebookId);
    }

    /**
     * Get notebook metadata (notebook brief + sources list).
     */
    public function getNotebookMetadata(string $notebookId): NotebookMetadataDTO
    {
        return $this->service->getNotebookMetadata($this->account->id, $notebookId);
    }

    // =========================================================================
    // Sources
    // =========================================================================

    /**
     * List sources in a notebook.
     *
     * @return SourceDTO[]
     */
    public function listSources(string $notebookId): array
    {
        return $this->service->listSources($this->account->id, $notebookId);
    }

    /**
     * Get source details.
     */
    public function getSource(string $notebookId, string $sourceId): SourceDTO
    {
        return $this->service->getSource($this->account->id, $notebookId, $sourceId);
    }

    /**
     * Get source full text.
     */
    public function getSourceFulltext(string $notebookId, string $sourceId): SourceFulltextDTO
    {
        return $this->service->getSourceFulltext($this->account->id, $notebookId, $sourceId);
    }

    /**
     * Get AI-generated summary and keywords for a source.
     *
     * @return array{summary: string, keywords: string}
     */
    public function getSourceGuide(string $notebookId, string $sourceId): array
    {
        return $this->service->getSourceGuide($this->account->id, $notebookId, $sourceId);
    }

    /**
     * Add a URL source.
     */
    public function addSourceUrl(string $notebookId, string $url): SourceDTO
    {
        return $this->service->addSourceUrl($this->account->id, $notebookId, $url);
    }

    /**
     * Add a plain-text source.
     */
    public function addSourceText(string $notebookId, string $title, string $content): SourceDTO
    {
        return $this->service->addSourceText($this->account->id, $notebookId, $title, $content);
    }

    /**
     * Add a file source.
     *
     * @param  array{wait?: bool, wait_timeout?: float, title?: string}  $options
     */
    public function addSourceFile(string $notebookId, string $filePath, array $options = []): SourceDTO
    {
        return $this->service->addSourceFile($this->account->id, $notebookId, $filePath, $options);
    }

    /**
     * Rename a source.
     */
    public function renameSource(string $notebookId, string $sourceId, string $newTitle): SourceDTO
    {
        return $this->service->renameSource($this->account->id, $notebookId, $sourceId, $newTitle);
    }

    /**
     * Delete a source.
     */
    public function deleteSource(string $notebookId, string $sourceId): bool
    {
        return $this->service->deleteSource($this->account->id, $notebookId, $sourceId);
    }

    /**
     * Refresh a URL/Drive source.
     */
    public function refreshSource(string $notebookId, string $sourceId): bool
    {
        return $this->service->refreshSource($this->account->id, $notebookId, $sourceId);
    }

    /**
     * Check if a source needs refreshing.
     */
    public function checkSourceFreshness(string $notebookId, string $sourceId): bool
    {
        return $this->service->checkSourceFreshness($this->account->id, $notebookId, $sourceId);
    }

    /**
     * Clean up all sources in a notebook.
     *
     * @return bool True if all sources were deleted successfully
     */
    public function cleanupNotebookSources(string $notebookId): bool
    {
        return $this->service->cleanupNotebookSources($this->account->id, $notebookId);
    }

    /**
     * Wait until a source finishes processing.
     *
     * @param  array{timeout?: int}  $options
     */
    public function waitUntilReady(string $notebookId, string $sourceId, array $options = []): SourceDTO
    {
        return $this->service->waitUntilReady($this->account->id, $notebookId, $sourceId, $options);
    }

    /**
     * Wait until a source is registered (visible server-side).
     *
     * @param  array{timeout?: float}  $options
     */
    public function waitUntilRegistered(string $notebookId, string $sourceId, array $options = []): SourceDTO
    {
        return $this->service->waitUntilRegistered($this->account->id, $notebookId, $sourceId, $options);
    }

    /**
     * Wait for multiple sources to become ready in parallel.
     *
     * @param  string[]  $sourceIds
     * @param  array{timeout?: float}  $options
     * @return SourceDTO[]
     */
    public function waitForSources(string $notebookId, array $sourceIds, array $options = []): array
    {
        return $this->service->waitForSources($this->account->id, $notebookId, $sourceIds, $options);
    }

    // =========================================================================
    // Q&A
    // =========================================================================

    /**
     * Ask a question in a notebook.
     *
     * @param  array{source_ids?: string[], conversation_id?: string}  $options
     */
    public function askQuestion(string $notebookId, string $question, array $options = []): AskResultDTO
    {
        return $this->service->askQuestion($this->account->id, $notebookId, $question, $options);
    }

    public function configure(string $notebookId, string $prompt): bool
    {
        return $this->service->configure($this->account->id, $notebookId, $prompt);
    }

    // =========================================================================
    // Sharing
    // =========================================================================

    /**
     * Get sharing status of a notebook.
     */
    public function getSharingStatus(string $notebookId): ShareStatusDTO
    {
        return $this->service->getSharingStatus($this->account->id, $notebookId);
    }

    /**
     * Set notebook as public.
     */
    public function setPublic(string $notebookId): ShareStatusDTO
    {
        return $this->service->setPublic($this->account->id, $notebookId);
    }

    /**
     * Set notebook as private.
     */
    public function setPrivate(string $notebookId): ShareStatusDTO
    {
        return $this->service->setPrivate($this->account->id, $notebookId);
    }

    // =========================================================================
    // Settings
    // =========================================================================

    /**
     * Get account settings, limits, and tier.
     */
    public function getSettings(): SettingsDTO
    {
        return $this->service->getSettings($this->account->id);
    }

    /**
     * Set the output language for the account.
     */
    public function setOutputLanguage(string $language): bool
    {
        return $this->service->setOutputLanguage($this->account->id, $language);
    }

    // =========================================================================
    // Accounts
    // =========================================================================

    /**
     * Initialize the account in FastAPI.
     *
     * @return array{status: string, account_id: string}
     */
    public function initializeAccount(): array
    {
        return $this->service->initializeAccount($this->account->id);
    }

    /**
     * Remove the account from FastAPI.
     *
     * @return array{status: string, account_id: string}
     */
    public function removeAccount(): array
    {
        return $this->service->removeAccount($this->account->id);
    }

    // =========================================================================
    // Health
    // =========================================================================

    /**
     * Check health of all accounts (delegates to underlying service).
     *
     * @return array<string, array{mtime_age_seconds?: int, mtime: string, is_connected: bool, status: string}>
     */
    public function healthAccounts(): array
    {
        return $this->service->healthAccounts();
    }

    // =========================================================================
    // Accessors
    // =========================================================================

    /**
     * Get the underlying TechAccount instance.
     */
    public function getAccount(): TechAccount
    {
        return $this->account;
    }

    /**
     * Get the underlying NotebookLMService instance.
     */
    public function getService(): NotebookLMService
    {
        return $this->service;
    }
}
