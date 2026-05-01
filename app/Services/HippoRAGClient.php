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
        $response = $this->request()->post($path, $payload)->throw();
        $data = $response->json();

        if (! is_array($data)) {
            throw new RuntimeException('HippoRAG API returned malformed JSON.');
        }

        if (($data['status'] ?? null) === 'error') {
            throw new RuntimeException((string) ($data['detail'] ?? 'HippoRAG API error.'));
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
}
