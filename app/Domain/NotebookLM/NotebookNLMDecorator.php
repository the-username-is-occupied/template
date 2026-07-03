<?php

declare(strict_types=1);

namespace App\Domain\NotebookLM;

use App\Domain\NotebookLM\DTOs\AskResultDTO;
use App\Domain\NotebookLM\DTOs\NotebookDescriptionDTO;
use App\Domain\NotebookLM\DTOs\NotebookDTO;
use App\Domain\NotebookLM\DTOs\NotebookMetadataDTO;
use App\Domain\NotebookLM\DTOs\ShareStatusDTO;
use App\Domain\NotebookLM\DTOs\SourceDTO;
use App\Domain\NotebookLM\DTOs\SourceFulltextDTO;
use App\Models\Notebook;

/**
 * Decorator for NotebookLMService that automatically uses a specific Notebook.
 *
 * This decorator wraps NotebookLMServiceDecorator and automatically injects
 * the notebook ID into all method calls, allowing syntax like:
 * $notebook->nlm()->askQuestion("Question?")
 */
class NotebookNLMDecorator
{
    protected NotebookLMServiceDecorator $decorator;

    protected Notebook $notebook;

    public function __construct(Notebook $notebook, ?NotebookLMServiceDecorator $decorator = null)
    {
        $this->notebook = $notebook;
        $this->decorator = $decorator ?? new NotebookLMServiceDecorator($notebook->techAccount);
    }

    // =========================================================================
    // Notebooks
    // =========================================================================

    /**
     * Get notebook details.
     */
    public function getNotebook(): NotebookDTO
    {
        return $this->decorator->getNotebook($this->notebook->nlm_notebook_id);
    }

    /**
     * Get AI-generated description for the notebook.
     */
    public function getNotebookDescription(): NotebookDescriptionDTO
    {
        return $this->decorator->getNotebookDescription($this->notebook->nlm_notebook_id);
    }

    /**
     * Rename the notebook.
     */
    public function renameNotebook(string $title): NotebookDTO
    {
        return $this->decorator->renameNotebook($this->notebook->nlm_notebook_id, $title);
    }

    /**
     * Delete the notebook.
     */
    public function deleteNotebook(): bool
    {
        return $this->decorator->deleteNotebook($this->notebook->nlm_notebook_id);
    }

    /**
     * Get notebook metadata (notebook brief + sources list).
     */
    public function getNotebookMetadata(): NotebookMetadataDTO
    {
        return $this->decorator->getNotebookMetadata($this->notebook->nlm_notebook_id);
    }

    // =========================================================================
    // Sources
    // =========================================================================

    /**
     * List sources in the notebook.
     *
     * @return SourceDTO[]
     */
    public function listSources(): array
    {
        return $this->decorator->listSources($this->notebook->nlm_notebook_id);
    }

    /**
     * Get source details.
     */
    public function getSource(string $sourceId): SourceDTO
    {
        return $this->decorator->getSource($this->notebook->nlm_notebook_id, $sourceId);
    }

    /**
     * Get source full text.
     */
    public function getSourceFulltext(string $sourceId): SourceFulltextDTO
    {
        return $this->decorator->getSourceFulltext($this->notebook->nlm_notebook_id, $sourceId);
    }

    /**
     * Get AI-generated summary and keywords for a source.
     *
     * @return array{summary: string, keywords: string}
     */
    public function getSourceGuide(string $sourceId): array
    {
        return $this->decorator->getSourceGuide($this->notebook->nlm_notebook_id, $sourceId);
    }

    /**
     * Add a URL source.
     */
    public function addSourceUrl(string $url): SourceDTO
    {
        return $this->decorator->addSourceUrl($this->notebook->nlm_notebook_id, $url);
    }

    /**
     * Add a plain-text source.
     */
    public function addSourceText(string $title, string $content): SourceDTO
    {
        return $this->decorator->addSourceText($this->notebook->nlm_notebook_id, $title, $content);
    }

    /**
     * Add a file source.
     *
     * @param  array{wait?: bool, wait_timeout?: float, title?: string}  $options
     */
    public function addSourceFile(string $filePath, array $options = []): SourceDTO
    {
        return $this->decorator->addSourceFile($this->notebook->nlm_notebook_id, $filePath, $options);
    }

    /**
     * Rename a source.
     */
    public function renameSource(string $sourceId, string $newTitle): SourceDTO
    {
        return $this->decorator->renameSource($this->notebook->nlm_notebook_id, $sourceId, $newTitle);
    }

    /**
     * Delete a source.
     */
    public function deleteSource(string $sourceId): bool
    {
        return $this->decorator->deleteSource($this->notebook->nlm_notebook_id, $sourceId);
    }

    /**
     * Refresh a URL/Drive source.
     */
    public function refreshSource(string $sourceId): bool
    {
        return $this->decorator->refreshSource($this->notebook->nlm_notebook_id, $sourceId);
    }

    /**
     * Check if a source needs refreshing.
     */
    public function checkSourceFreshness(string $sourceId): bool
    {
        return $this->decorator->checkSourceFreshness($this->notebook->nlm_notebook_id, $sourceId);
    }

    /**
     * Wait until a source finishes processing.
     *
     * @param  array{timeout?: int}  $options
     */
    public function waitUntilReady(string $sourceId, array $options = []): SourceDTO
    {
        return $this->decorator->waitUntilReady($this->notebook->nlm_notebook_id, $sourceId, $options);
    }

    /**
     * Wait until a source is registered (visible server-side).
     *
     * @param  array{timeout?: float}  $options
     */
    public function waitUntilRegistered(string $sourceId, array $options = []): SourceDTO
    {
        return $this->decorator->waitUntilRegistered($this->notebook->nlm_notebook_id, $sourceId, $options);
    }

    /**
     * Wait for multiple sources to become ready in parallel.
     *
     * @param  string[]  $sourceIds
     * @param  array{timeout?: float}  $options
     * @return SourceDTO[]
     */
    public function waitForSources(array $sourceIds, array $options = []): array
    {
        return $this->decorator->waitForSources($this->notebook->nlm_notebook_id, $sourceIds, $options);
    }

    // =========================================================================
    // Q&A
    // =========================================================================

    /**
     * Ask a question in the notebook.
     *
     * @param  array{source_ids?: string[], conversation_id?: string}  $options
     */
    public function askQuestion(string $question, array $options = []): AskResultDTO
    {
        return $this->decorator->askQuestion($this->notebook->nlm_notebook_id, $question, $options);
    }

    public function configure(string $prompt): bool
    {
        return $this->decorator->configure($this->notebook->nlm_notebook_id, $prompt);
    }

    // =========================================================================
    // Sharing
    // =========================================================================

    /**
     * Get sharing status of the notebook.
     */
    public function getSharingStatus(): ShareStatusDTO
    {
        return $this->decorator->getSharingStatus($this->notebook->nlm_notebook_id);
    }

    /**
     * Set the notebook as public.
     */
    public function setPublic(): ShareStatusDTO
    {
        return $this->decorator->setPublic($this->notebook->nlm_notebook_id);
    }

    /**
     * Set the notebook as private.
     */
    public function setPrivate(): ShareStatusDTO
    {
        return $this->decorator->setPrivate($this->notebook->nlm_notebook_id);
    }
}
