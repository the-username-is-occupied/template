<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Source;
use App\Models\UserSpace;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

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
     *     response: array<string, mixed>,
     *     mode: string,
     *     graph_info: array<string, mixed>,
     *     chunks: array<int, array<string, mixed>>
     * }
     */
    public function index(
        UserSpace $userSpace,
        array $files,
        string $pastedText,
        string $llmModelName,
        string $indexMode,
        int $chunkSize,
        float $overlapRatio,
    ): array {
        $mode = $indexMode === 'chunk' ? 'chunk' : 'index';
        $sources = [];
        $sourceInputs = [];
        $fileResults = [];
        $estimatedPromptTokens = 0;

        foreach ($files as $file) {
            $contents = (string) file_get_contents($file->getRealPath());
            $originalName = $this->sanitizeFilename($file->getClientOriginalName());
            $tokens = $this->tokenUsage->estimateTokens($contents);
            $estimatedPromptTokens += $tokens;
            $fileResults[] = [
                'filename' => $originalName,
                'tokens' => $tokens,
                'cost' => $this->tokenUsage->estimateCost($llmModelName, $tokens, 0),
            ];

            if ($mode === 'index') {
                $source = $this->persistSource($userSpace, $file, $contents, $originalName);
                $sources[] = $source;
                $sourceInputs[] = [
                    'source_uuid' => $source->uuid,
                    'filename' => $source->original_name,
                    'text' => $contents,
                ];

                continue;
            }

            $sourceInputs[] = [
                'source_uuid' => (string) Str::uuid(),
                'filename' => $originalName,
                'text' => $contents,
            ];
        }

        $normalizedPastedText = trim($pastedText);
        if ($normalizedPastedText !== '') {
            $clipboardFilename = 'clipboard_'.now()->format('Ymd_His').'.txt';
            $clipboardTokens = $this->tokenUsage->estimateTokens($normalizedPastedText);
            $estimatedPromptTokens += $clipboardTokens;
            $fileResults[] = [
                'filename' => $clipboardFilename,
                'tokens' => $clipboardTokens,
                'cost' => $this->tokenUsage->estimateCost($llmModelName, $clipboardTokens, 0),
            ];

            if ($mode === 'index') {
                $clipboardPath = sprintf('%s/%s', $userSpace->storageDirectory(), $clipboardFilename);
                Storage::disk('local')->put($clipboardPath, $normalizedPastedText);
                $clipboardHash = hash('sha256', $normalizedPastedText.$userSpace->uuid);
                $source = Source::query()->firstOrCreate(
                    ['sha256' => $clipboardHash],
                    [
                        'user_space_id' => $userSpace->id,
                        'filename' => $clipboardFilename,
                        'original_name' => $clipboardFilename,
                        'mime_type' => 'text/plain',
                        'size' => strlen($normalizedPastedText),
                        'path' => $clipboardPath,
                    ],
                );
                $sources[] = $source;
                $sourceInputs[] = [
                    'source_uuid' => $source->uuid,
                    'filename' => $source->original_name,
                    'text' => $normalizedPastedText,
                ];
            } else {
                $sourceInputs[] = [
                    'source_uuid' => (string) Str::uuid(),
                    'filename' => $clipboardFilename,
                    'text' => $normalizedPastedText,
                ];
            }
        }

        $startedAt = microtime(true);
        $response = $this->client->index([
            'work_dir' => $userSpace->workDir(),
            'sources' => $sourceInputs,
            'llm_model_name' => $llmModelName,
            'mode' => $mode,
            'chunk_size' => $chunkSize,
            'overlap_ratio' => $overlapRatio,
        ]);
        $responseTimeMs = (int) round((microtime(true) - $startedAt) * 1000);

        $promptTokens = (int) data_get($response, 'token_usage.prompt_tokens', $estimatedPromptTokens);
        $completionTokens = (int) data_get($response, 'token_usage.completion_tokens', 0);
        $estimatedCost = $this->tokenUsage->estimateCost($llmModelName, $promptTokens, $completionTokens);
        $this->tokenUsage->log(
            userSpace: $userSpace,
            operationType: 'indexing',
            model: $llmModelName,
            promptTokens: $promptTokens,
            completionTokens: $completionTokens,
            estimatedCostUsd: $estimatedCost,
        );

        return [
            'sources' => Collection::make($sources)->all(),
            'files' => $fileResults,
            'num_files' => count($sourceInputs),
            'prompt_tokens' => $promptTokens,
            'completion_tokens' => $completionTokens,
            'estimated_cost_usd' => $estimatedCost,
            'response_time_ms' => $responseTimeMs,
            'response' => $response,
            'mode' => $mode,
            'graph_info' => (array) data_get($response, 'graph_info', []),
            'chunks' => (array) data_get($response, 'chunks', []),
        ];
    }

    private function persistSource(UserSpace $userSpace, UploadedFile $file, string $contents, string $originalName): Source
    {
        $sha256 = hash('sha256', $contents);
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
            return Source::query()->create([
                'user_space_id' => $userSpace->id,
                'filename' => $originalName,
                'original_name' => $originalName,
                'mime_type' => $file->getClientMimeType() ?: 'text/plain',
                'size' => $file->getSize() ?: strlen($contents),
                'sha256' => hash('sha256', $sha256.$userSpace->uuid),
                'path' => $path,
            ]);
        }

        return $source;
    }

    private function sanitizeFilename(string $filename): string
    {
        $basename = basename($filename);
        $sanitized = preg_replace('/[^A-Za-z0-9._-]+/', '_', $basename) ?: 'source.txt';

        return trim($sanitized, '._') ?: 'source.txt';
    }
}
