<?php

declare(strict_types=1);

namespace App\Domain\NotebookLM;

use App\Domain\NotebookLM\DTOs\AskResultDTO;
use App\Domain\NotebookLM\DTOs\NotebookDTO;
use App\Domain\NotebookLM\DTOs\SharedUserDTO;
use App\Domain\NotebookLM\DTOs\ShareStatusDTO;
use App\Domain\NotebookLM\DTOs\SourceDTO;
use App\Domain\NotebookLM\DTOs\SourceFulltextDTO;
use App\Domain\NotebookLM\DTOs\SuggestedTopicDTO;
use App\Domain\NotebookLM\DTOs\NotebookDescriptionDTO;
use App\Domain\NotebookLM\DTOs\NotebookMetadataDTO;
use App\Domain\NotebookLM\DTOs\NotebookMetadataSourceDTO;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Service for communicating with NotebookLM FastAPI service.
 *
 * This service wraps HTTP calls to the FastAPI service running in the notebooklm container.
 * The FastAPI service maintains a pool of initialized NotebookLMClient instances.
 *
 * @see /docs/notebooklm/fastapi.md for API details and response formats.
 */
class NotebookLMService
{
    protected string $baseUrl;

    protected int $timeout;

    public function __construct()
    {
        $this->baseUrl = config('notebook-lm.url', 'http://notebooklm:8000');
        $this->timeout = config('notebook-lm.timeout', 180);
    }

    public function getBaseUrl(): string
    {
        return $this->baseUrl;
    }

    // =========================================================================
    // Notebooks
    // =========================================================================

    /**
     * List all notebooks for an account.
     *
     * @return NotebookDTO[]
     */
    public function listNotebooks(string $accountId): array
    {
        $response = $this->get("/accounts/{$accountId}/notebooks");

        return NotebookDTO::collect($response['notebooks']);
    }

    /**
     * Create a new notebook for an account.
     */
    public function createNotebook(string $accountId, string $title): NotebookDTO
    {
        $response = $this->post("/accounts/{$accountId}/notebooks", ['title' => $title]);

        return NotebookDTO::from($response['notebook']);
    }

    /**
     * Get notebook details.
     */
    public function getNotebook(string $accountId, string $notebookId): NotebookDTO
    {
        $response = $this->get("/accounts/{$accountId}/notebooks/{$notebookId}");

        return NotebookDTO::from($response['notebook']);
    }

    /**
     * Rename a notebook.
     */
    public function renameNotebook(string $accountId, string $notebookId, string $title): NotebookDTO
    {
        $response = $this->put("/accounts/{$accountId}/notebooks/{$notebookId}", ['title' => $title]);

        return NotebookDTO::from($response['notebook']);
    }

    /**
     * Delete a notebook.
     */
    public function deleteNotebook(string $accountId, string $notebookId): bool
    {
        $response = $this->delete("/accounts/{$accountId}/notebooks/{$notebookId}");

        return $response['success'];
    }

    /**
     * Get AI-generated description for a notebook.
     */
    public function getNotebookDescription(string $accountId, string $notebookId): NotebookDescriptionDTO
    {
        $response = $this->get("/accounts/{$accountId}/notebooks/{$notebookId}/description");

        // Ensure suggested_topics are DTOs before constructing the parent DTO
        $response['description']['suggested_topics'] = SuggestedTopicDTO::collect($response['description']['suggested_topics'] ?? []);

        return NotebookDescriptionDTO::from($response['description']);
    }

    // =========================================================================
    // Sources
    // =========================================================================

    /**
     * List sources in a notebook.
     *
     * @return SourceDTO[]
     */
    public function listSources(string $accountId, string $notebookId): array
    {
        $response = $this->get("/accounts/{$accountId}/notebooks/{$notebookId}/sources");

        return SourceDTO::collect($response['sources']);
    }

    /**
     * Get source details.
     */
    public function getSource(string $accountId, string $notebookId, string $sourceId): SourceDTO
    {
        $response = $this->get("/accounts/{$accountId}/notebooks/{$notebookId}/sources/{$sourceId}");

        return SourceDTO::from($response['source']);
    }

    /**
     * Get source full text.
     */
    public function getSourceFulltext(string $accountId, string $notebookId, string $sourceId): SourceFulltextDTO
    {
        $response = $this->get("/accounts/{$accountId}/notebooks/{$notebookId}/sources/{$sourceId}/fulltext");

        return SourceFulltextDTO::from($response);
    }

    /**
     * Get AI-generated summary and keywords for a source.
     *
     * @return array{summary: string, keywords: string}
     */
    public function getSourceGuide(string $accountId, string $notebookId, string $sourceId): array
    {
        return $this->get("/accounts/{$accountId}/notebooks/{$notebookId}/sources/{$sourceId}/guide");
    }

    /**
     * Add a URL source.
     */
    public function addSourceUrl(string $accountId, string $notebookId, string $url): SourceDTO
    {
        $response = $this->post("/accounts/{$accountId}/notebooks/{$notebookId}/sources/url", ['url' => $url]);

        return SourceDTO::from($response['source']);
    }

    /**
     * Add a plain-text source.
     */
    public function addSourceText(string $accountId, string $notebookId, string $title, string $content): SourceDTO
    {
        $response = $this->post("/accounts/{$accountId}/notebooks/{$notebookId}/sources/text", [
            'title' => $title,
            'content' => $content,
        ]);

        return SourceDTO::from($response['source']);
    }

    /**
     * Add a file source.
     *
     * @param  array{wait?: bool, wait_timeout?: float, title?: string}  $options
     */
    public function addSourceFile(string $accountId, string $notebookId, string $filePath, array $options = []): SourceDTO
    {
        $data = array_filter([
            'file_path' => $filePath,
            'wait' => $options['wait'] ?? null,
            'wait_timeout' => $options['wait_timeout'] ?? null,
            'title' => $options['title'] ?? null,
        ]);

        $response = $this->post("/accounts/{$accountId}/notebooks/{$notebookId}/sources/file", $data);

        return SourceDTO::from($response['source']);
    }

    /**
     * Rename a source.
     */
    public function renameSource(string $accountId, string $notebookId, string $sourceId, string $newTitle): SourceDTO
    {
        $response = $this->post("/accounts/{$accountId}/notebooks/{$notebookId}/sources/{$sourceId}/rename", [
            'new_title' => $newTitle,
        ]);

        return SourceDTO::from($response['source']);
    }

    /**
     * Delete a source.
     */
    public function deleteSource(string $accountId, string $notebookId, string $sourceId): bool
    {
        $response = $this->delete("/accounts/{$accountId}/notebooks/{$notebookId}/sources/{$sourceId}");

        return $response['success'];
    }

    /**
     * Refresh a URL/Drive source.
     */
    public function refreshSource(string $accountId, string $notebookId, string $sourceId): bool
    {
        $response = $this->post("/accounts/{$accountId}/notebooks/{$notebookId}/sources/{$sourceId}/refresh");

        return $response['success'];
    }

    /**
     * Check if a source needs refreshing.
     */
    public function checkSourceFreshness(string $accountId, string $notebookId, string $sourceId): bool
    {
        $response = $this->get("/accounts/{$accountId}/notebooks/{$notebookId}/sources/{$sourceId}/freshness");

        return $response['is_fresh'];
    }

    /**
     * Wait until a source finishes processing.
     *
     * @param  array{timeout?: int}  $options
     */
    public function waitUntilReady(string $accountId, string $notebookId, string $sourceId, array $options = []): SourceDTO
    {
        $response = $this->post("/accounts/{$accountId}/notebooks/{$notebookId}/sources/{$sourceId}/wait_until_ready", [
            'timeout' => $options['timeout'] ?? 120,
        ]);

        return SourceDTO::from($response['source']);
    }

    /**
     * Wait until a source is registered (visible server-side).
     *
     * @param  array{timeout?: float}  $options
     */
    public function waitUntilRegistered(string $accountId, string $notebookId, string $sourceId, array $options = []): SourceDTO
    {
        $response = $this->post("/accounts/{$accountId}/notebooks/{$notebookId}/sources/{$sourceId}/wait_until_registered", [
            'timeout' => $options['timeout'] ?? 30.0,
        ]);

        return SourceDTO::from($response['source']);
    }

    /**
     * Wait for multiple sources to become ready in parallel.
     *
     * @param  string[]  $sourceIds
     * @param  array{timeout?: float}  $options
     * @return SourceDTO[]
     */
    public function waitForSources(string $accountId, string $notebookId, array $sourceIds, array $options = []): array
    {
        $response = $this->post("/accounts/{$accountId}/notebooks/{$notebookId}/sources/wait_for_sources", [
            'source_ids' => $sourceIds,
            'timeout' => $options['timeout'] ?? 120.0,
        ]);

        return SourceDTO::collect($response['sources']);
    }

    /**
     * Get notebook metadata (notebook brief + sources list).
     */
    public function getNotebookMetadata(string $accountId, string $notebookId): NotebookMetadataDTO
    {
        $response = $this->get("/accounts/{$accountId}/notebooks/{$notebookId}/metadata");

        // Cast notebook to NotebookDTO
        $response['metadata']['notebook'] = NotebookDTO::from($response['metadata']['notebook']);

        // Cast each source to NotebookMetadataSourceDTO
        $response['metadata']['sources'] = NotebookMetadataSourceDTO::collect($response['metadata']['sources'] ?? []);

        return NotebookMetadataDTO::from($response['metadata']);
    }

    // =========================================================================
    // Q&A
    // =========================================================================

    /**
     * Ask a question in a notebook.
     *
     * @param  array{source_ids?: string[], conversation_id?: string}  $options
     */
    public function askQuestion(
        string $accountId,
        string $notebookId,
        string $question,
        array $options = [],
    ): AskResultDTO {
        $data = array_filter([
            'notebook_id' => $notebookId,
            'question' => $question,
            'source_ids' => $options['source_ids'] ?? null,
            'conversation_id' => $options['conversation_id'] ?? null,
        ]);

        $response = $this->post("/accounts/{$accountId}/notebooks/ask", $data);

        return AskResultDTO::from($response['result']);
    }

    // =========================================================================
    // Sharing
    // =========================================================================

    /**
     * Get sharing status of a notebook.
     */
    public function getSharingStatus(string $accountId, string $notebookId): ShareStatusDTO
    {
        $response = $this->get("/accounts/{$accountId}/notebooks/{$notebookId}/sharing");

        return $this->makeShareStatusDTO($response['status']);
    }

    /**
     * Set notebook as public.
     */
    public function setPublic(string $accountId, string $notebookId): ShareStatusDTO
    {
        $response = $this->post("/accounts/{$accountId}/notebooks/{$notebookId}/sharing/public");

        return $this->makeShareStatusDTO($response['status']);
    }

    /**
     * Set notebook as private.
     */
    public function setPrivate(string $accountId, string $notebookId): ShareStatusDTO
    {
        $response = $this->post("/accounts/{$accountId}/notebooks/{$notebookId}/sharing/private");

        return $this->makeShareStatusDTO($response['status']);
    }

    // =========================================================================
    // Accounts
    // =========================================================================

    /**
     * Initialize an account in FastAPI.
     *
     * @return array{status: string, account_id: string}
     */
    public function initializeAccount(string $accountId): array
    {
        return $this->post("/accounts/{$accountId}");
    }

    /**
     * Remove an account from FastAPI.
     *
     * @return array{status: string, account_id: string}
     */
    public function removeAccount(string $accountId): array
    {
        return $this->delete("/accounts/{$accountId}");
    }

    // =========================================================================
    // Health
    // =========================================================================

    /**
     * Check health of all accounts.
     *
     * @return array<string, array{mtime_age_seconds?: int, mtime: string, is_connected: bool, status: string}>
     */
    public function healthAccounts(): array
    {
        return $this->get('/health/accounts');
    }

    // =========================================================================
    // HTTP layer
    // =========================================================================

    /**
     * @return array<string, mixed>
     */
    protected function get(string $path): array
    {
        return $this->send('get', $path);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function post(string $path, array $data = []): array
    {
        return $this->send('post', $path, $data);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function put(string $path, array $data = []): array
    {
        return $this->send('put', $path, $data);
    }

    /**
     * @return array<string, mixed>
     */
    protected function delete(string $path): array
    {
        return $this->send('delete', $path);
    }

    /**
     * Execute an HTTP request, log the response, throw on error.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function send(string $method, string $path, array $data = []): array
    {
        /** @var Response $response */
        $response = $this->client()->{$method}($this->baseUrl.$path, $data);

        $this->logResponse($response, $path);
        $this->handleError($response, $path);

        return $response->json();
    }

    /**
     * Build a pre-configured HTTP client instance.
     */
    protected function client(): PendingRequest
    {
        return Http::timeout($this->timeout);
    }

    // =========================================================================
    // Response handling
    // =========================================================================

    /**
     * Log basic metadata from an HTTP response.
     */
    protected function logResponse(Response $response, string $path): void
    {
        Log::info('NotebookLM FastAPI Response', [
            'path' => $path,
            'status' => $response->status(),
            'response_time_ms' => $response->header('X-Response-Time-Ms'),
        ]);
    }

    /**
     * Throw a RuntimeException when the response is not successful.
     */
    protected function handleError(Response $response, string $path): void
    {
        if ($response->successful()) {
            return;
        }

        $body = $response->json() ?? [];

        $error = $body['error'] ?? 'UnknownError';
        $message = $body['message'] ?? $response->body();

        Log::error('NotebookLM FastAPI Error', [
            'path' => $path,
            'status' => $response->status(),
            'error' => $error,
            'message' => $message,
            'account_id' => $body['account_id'] ?? null,
            'response_time_ms' => $body['response_time_ms'] ?? 0,
        ]);

        throw new \RuntimeException(
            sprintf('NotebookLM Error [%s]: %s', $error, $message),
            $response->status(),
        );
    }

    // =========================================================================
    // DTO helpers
    // =========================================================================

    /**
     * Build a ShareStatusDTO, hydrating shared_users when present.
     *
     * @param  array<string, mixed>  $status
     */
    protected function makeShareStatusDTO(array $status): ShareStatusDTO
    {
        if (! empty($status['shared_users'])) {
            $status['shared_users'] = SharedUserDTO::collect($status['shared_users']);
        }

        return ShareStatusDTO::from($status);
    }
}
