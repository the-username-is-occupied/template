<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Source;
use App\Models\UserSpace;
use Illuminate\Http\UploadedFile;

class HippoRAGIndexingService
{
    public function __construct(
        private readonly HippoRAGClient $client,
        private readonly TokenUsageService $tokenUsage,
    ) {}

    /**
     * @param  array<int, UploadedFile>  $files
     * @return array{
     *     sources: array<int, Source>,
     *     files: array<int, array{filename: string, tokens: int, cost: float}>,
     *     num_files: int,
     *     prompt_tokens: int,
     *     completion_tokens: int,
     *     estimated_cost_usd: float,
     *     response_time_ms: int,
     *     response: array<string, mixed>
     * }
     */
    public function index(UserSpace $userSpace, array $files): array
    {
        $sources = [];
        $fileResults = [];
        $documents = [];
        $promptTokens = 0;

        foreach ($files as $file) {
            $contents = (string) file_get_contents($file->getRealPath());
            $sha256 = hash('sha256', $contents);
            $originalName = $this->sanitizeFilename($file->getClientOriginalName());

            $path = $file->storeAs($userSpace->storageDirectory(), $originalName);
            $source = Source::query()->firstOrCreate(
                ['sha256' => $sha256],
                [
                    'user_space_id' => $userSpace->id,
                    'filename' => $originalName,
                    'original_name' => $originalName,
                    'mime_type' => $file->getClientMimeType() ?: 'text/plain',
                    'size' => $file->getSize() ?: strlen($contents),
                    'path' => $path,
                ],
            );

            if ((int) $source->user_space_id !== (int) $userSpace->id) {
                $source = Source::query()->create([
                    'user_space_id' => $userSpace->id,
                    'filename' => $originalName,
                    'original_name' => $originalName,
                    'mime_type' => $file->getClientMimeType() ?: 'text/plain',
                    'size' => $file->getSize() ?: strlen($contents),
                    'sha256' => hash('sha256', $sha256.$userSpace->uuid),
                    'path' => $path,
                ]);
            }

            $document = "[SOURCE_ID:{$source->uuid}]\n{$contents}";
            $tokens = $this->tokenUsage->estimateTokens($document);
            $documents[] = $document;
            $sources[] = $source;
            $promptTokens += $tokens;
            $fileResults[] = [
                'filename' => $source->original_name,
                'tokens' => $tokens,
                'cost' => $this->tokenUsage->estimateCost((string) config('hipporag.default_model'), $tokens, 0),
            ];
        }

        $startedAt = microtime(true);
        $response = $this->client->index([
            'work_dir' => $userSpace->workDir(),
            'documents' => $documents,
            'llm_model' => (string) config('hipporag.default_model'),
            'embedding_model' => (string) config('hipporag.default_embedding_model'),
            'llm_base_url' => config('hipporag.llm_base_url'),
            'embedding_base_url' => config('hipporag.embedding_base_url'),
            'llm_api_key' => config('hipporag.llm_api_key'),
        ]);
        $responseTimeMs = (int) round((microtime(true) - $startedAt) * 1000);

        $model = (string) config('hipporag.default_model');
        $estimatedCost = $this->tokenUsage->estimateCost($model, $promptTokens, 0);
        $this->tokenUsage->log(
            userSpace: $userSpace,
            operationType: 'indexing',
            model: $model,
            promptTokens: $promptTokens,
            completionTokens: null,
            estimatedCostUsd: $estimatedCost,
        );

        return [
            'sources' => $sources,
            'files' => $fileResults,
            'num_files' => count($sources),
            'prompt_tokens' => $promptTokens,
            'completion_tokens' => 0,
            'estimated_cost_usd' => $estimatedCost,
            'response_time_ms' => $responseTimeMs,
            'response' => $response,
        ];
    }

    private function sanitizeFilename(string $filename): string
    {
        $basename = basename($filename);
        $sanitized = preg_replace('/[^A-Za-z0-9._-]+/', '_', $basename) ?: 'source.txt';

        return trim($sanitized, '._') ?: 'source.txt';
    }
}
