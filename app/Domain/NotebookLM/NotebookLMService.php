<?php

declare(strict_types=1);

namespace App\Domain\NotebookLM;

use App\Domain\NotebookLM\DTOs\AskResultDTO;
use App\Domain\NotebookLM\DTOs\NotebookDTO;
use App\Domain\NotebookLM\DTOs\SharedUserDTO;
use App\Domain\NotebookLM\DTOs\ShareStatusDTO;
use App\Domain\NotebookLM\DTOs\SourceDTO;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Service for communicating with NotebookLM FastAPI service.
 *
 * This service wraps HTTP calls to the FastAPI service running in the notebooklm container.
 * The FastAPI service maintains a pool of initialized NotebookLMClient instances.
 *
 * @see https://github.com/your-repo/docs/notebook-py/python-api.md
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

    /**
     * Get the base URL for the FastAPI service.
     */
    public function getBaseUrl(): string
    {
        return $this->baseUrl;
    }

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
        $response = $this->post("/accounts/{$accountId}/notebooks", [
            'title' => $title,
        ]);

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
        $response = $this->put("/accounts/{$accountId}/notebooks/{$notebookId}", [
            'title' => $title,
        ]);

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
     * Ask a question in a notebook.
     *
     * @param  array{source_ids?: string[], conversation_id?: string}  $options
     */
    public function askQuestion(string $accountId, string $notebookId, string $question, array $options = []): AskResultDTO
    {
        $data = [
            'notebook_id' => $notebookId,
            'question' => $question,
        ];

        if (! empty($options['source_ids'])) {
            $data['source_ids'] = $options['source_ids'];
        }

        if (! empty($options['conversation_id'])) {
            $data['conversation_id'] = $options['conversation_id'];
        }

        $response = $this->post("/accounts/{$accountId}/notebooks/ask", $data);

        return AskResultDTO::from($response['result']);
    }

    /**
     * Check health of all accounts.
     *
     * @return array<string, array{mtime_age_seconds?: int, mtime: string, is_connected: bool, status: string}>
     */
    public function healthAccounts(): array
    {
        $response = $this->get('/health/accounts');

        return $response;
    }

    /**
     * Get sharing status of a notebook.
     */
    public function getSharingStatus(string $accountId, string $notebookId): ShareStatusDTO
    {
        $response = $this->get("/accounts/{$accountId}/notebooks/{$notebookId}/sharing");

        // Map shared_users to DTOs if present
        $status = $response['status'];
        if (! empty($status['shared_users'])) {
            $status['shared_users'] = SharedUserDTO::collect($status['shared_users']);
        }

        return ShareStatusDTO::from($status);
    }

    /**
     * Set notebook as public.
     */
    public function setPublic(string $accountId, string $notebookId): ShareStatusDTO
    {
        $response = $this->post("/accounts/{$accountId}/notebooks/{$notebookId}/sharing/public", []);

        // Map shared_users to DTOs if present
        $status = $response['status'];
        if (! empty($status['shared_users'])) {
            $status['shared_users'] = SharedUserDTO::collect($status['shared_users']);
        }

        return ShareStatusDTO::from($status);
    }

    /**
     * Set notebook as private.
     */
    public function setPrivate(string $accountId, string $notebookId): ShareStatusDTO
    {
        $response = $this->post("/accounts/{$accountId}/notebooks/{$notebookId}/sharing/private", []);

        // Map shared_users to DTOs if present
        $status = $response['status'];
        if (! empty($status['shared_users'])) {
            $status['shared_users'] = SharedUserDTO::collect($status['shared_users']);

        }

        return ShareStatusDTO::from($status);
    }

    /**
     * Initialize an account in FastAPI.
     *
     * @return array{status: string, account_id: string}
     */
    public function initializeAccount(string $accountId): array
    {
        return $this->post("/accounts/{$accountId}", []);
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

    /**
     * Make a GET request to FastAPI.
     *
     * @return array<string, mixed>
     */
    protected function get(string $path): array
    {
        $response = Http::timeout($this->timeout)
            ->get($this->baseUrl.$path);

        $this->postprocess($response, $path);

        return $response->json();
    }

    /**
     * Make a POST request to FastAPI.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function post(string $path, array $data): array
    {
        $response = Http::timeout($this->timeout)
            ->post($this->baseUrl.$path, $data);

        $this->postprocess($response, $path);

        return $response->json();
    }

    /**
     * Make a PUT request to FastAPI.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function put(string $path, array $data): array
    {
        $response = Http::timeout($this->timeout)
            ->put($this->baseUrl.$path, $data);

        $this->postprocess($response, $path);

        return $response->json();
    }

    /**
     * Make a DELETE request to FastAPI.
     *
     * @return array<string, mixed>
     */
    protected function delete(string $path): array
    {
        $response = Http::timeout($this->timeout)
            ->delete($this->baseUrl.$path);

        $this->postprocess($response, $path);

        return $response->json();
    }

    protected function postprocess(Response|PromiseInterface $response, string $path): array
    {
        if ($response instanceof PromiseInterface) {
            $response = $response->wait();
        }

        $this->handleResponseTime($response, $path);

        $this->handleError($response, $path);

        return $response->json();
    }

    protected function handleResponseTime(Response $response, string $path): void
    {
        Log::info('NotebookLM FastAPI Response', [
            'status' => $response->status(),
            'response_time_ms' => $response->header('X-Response-Time-Ms'),
            'path' => $path,
        ]);
    }

    /**
     * Handle error responses from FastAPI.
     *
     * @param  Response  $response
     */
    protected function handleError($response, string $path): void
    {
        if ($response->successful()) {
            return;
        }

        $body = $response->json();

        $error = $body['error'] ?? 'UnknownError';
        $message = $body['message'] ?? $response->body();
        $accountId = $body['account_id'] ?? null;
        $responseTimeMs = $body['response_time_ms'] ?? 0;

        Log::error('NotebookLM FastAPI Error', [
            'path' => $path,
            'error' => $error,
            'message' => $message,
            'account_id' => $accountId,
            'response_time_ms' => $responseTimeMs,
            'status' => $response->status(),
        ]);

        throw new \RuntimeException(
            "NotebookLM Error [{$error}]: {$message}",
            $response->status()
        );
    }
}
