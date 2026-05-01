<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class HippoRAGClient
{
    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function index(array $payload): array
    {
        return $this->post('/index', $payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function query(array $payload): array
    {
        return $this->post('/query', $payload);
    }

    /**
     * @return array<string, mixed>
     */
    public function delete(string $workDir): array
    {
        return $this->post('/delete', ['work_dir' => $workDir]);
    }

    /**
     * @return array<string, mixed>
     */
    public function health(): array
    {
        return $this->request()->get('/health')->throw()->json() ?? [];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function post(string $path, array $payload): array
    {
        $this->extendExecutionTime();

        $response = $this->request()->post($path, $payload)->throw();
        $data = $response->json();

        if (! is_array($data)) {
            throw new RuntimeException('HippoRAG API returned malformed JSON.');
        }

        if (($data['status'] ?? null) === 'error') {
            $detail = trim((string) ($data['detail'] ?? ''));

            throw new RuntimeException($detail !== '' ? $detail : 'HippoRAG API error.');
        }

        return $data;
    }

    private function request(): PendingRequest
    {
        return Http::baseUrl($this->apiUrl())
            ->acceptJson()
            ->asJson()
            ->timeout((int) config('hipporag.timeout'));
    }

    private function apiUrl(): string
    {
        $apiUrl = (string) config('hipporag.api_url');

        if ($this->isContainerRuntime() && $this->isLocalhostUrl($apiUrl)) {
            return (string) config('hipporag.internal_api_url');
        }

        return $apiUrl;
    }

    public function workDir(string $userSpaceUuid): string
    {
        $prefix = rtrim((string) config('hipporag.work_dir_prefix'), '/');

        return $prefix.'/userspace_'.$userSpaceUuid;
    }

    private function extendExecutionTime(): void
    {
        $timeout = max(1, (int) config('hipporag.timeout'));

        if (function_exists('set_time_limit')) {
            set_time_limit($timeout + 30);
        }
    }

    private function isContainerRuntime(): bool
    {
        return file_exists('/.dockerenv');
    }

    private function isLocalhostUrl(string $url): bool
    {
        return in_array(parse_url($url, PHP_URL_HOST), ['localhost', '127.0.0.1'], true);
    }
}
