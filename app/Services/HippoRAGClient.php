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
        return Http::baseUrl((string) config('hipporag.api_url'))
            ->acceptJson()
            ->asJson()
            ->timeout((int) config('hipporag.timeout'));
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
}
