<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Source;
use App\Models\UserSpace;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class HippoRAGIndexingService
{
    public function __construct(
        private readonly HippoRAGClient $client,
        private readonly HippoRAGConnectionConfig $connectionConfig,
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
     *     chunks: array<int, array<string, mixed>>,
     *     warnings: array<int, string>
     * }
     */
    public function index(
        UserSpace $userSpace,
        array $files,
        string $pastedText,
        string $llmModelName,
        ?string $llmProvider,
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

        $documents = $this->buildDocuments($sourceInputs);

        $warnings = [];
        $startedAt = microtime(true);
        $indexPayload = [
            'work_dir' => $userSpace->workDir(),
            'sources' => $sourceInputs,
            'documents' => $documents,
            'llm_model' => $llmModelName,
            'embedding_model' => (string) config('hipporag.default_embedding_model'),
            'mode' => $mode,
            'chunk_size' => $chunkSize,
            'overlap_ratio' => $overlapRatio,
            ...$this->connectionConfig->build($llmModelName, $llmProvider),
        ];

        try {
            $response = $this->client->index($indexPayload);
        } catch (Throwable $throwable) {
            if ($this->shouldRetryWithoutSourceRegistry($mode, $throwable, $indexPayload)) {
                unset($indexPayload['sources']);
                report($throwable);
                logger()->warning('HippoRAG index retried without sources registry.', [
                    'work_dir' => $userSpace->workDir(),
                    'mode' => $mode,
                    'reason' => $throwable->getMessage(),
                ]);
                $response = $this->client->index($indexPayload);
                $warnings[] = 'HippoRAG source UUID registry is unavailable; indexing continued without registry payload.';
            } else {
                if ($mode !== 'chunk') {
                    throw $throwable;
                }

                report($throwable);
                $response = [
                    'status' => 'error',
                    'detail' => $throwable->getMessage(),
                    'token_usage' => [
                        'prompt_tokens' => $estimatedPromptTokens,
                        'completion_tokens' => 0,
                    ],
                ];
                $warnings[] = sprintf(
                    'HippoRAG chunk API failed (%s).',
                    trim($throwable->getMessage()) !== '' ? $throwable->getMessage() : 'unknown error'
                );
            }
        }
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
            'chunks' => $this->extractChunks($response),
            'warnings' => $warnings,
        ];
    }

    /**
     * @return array<int, array{index: int, source_uuid: string|null, token_count: int|null, text: string}>
     */
    private function extractChunks(array $response): array
    {
        $chunks = $this->normalizeChunks(data_get($response, 'chunks', []));
        if ($chunks !== []) {
            return $chunks;
        }

        $chunks = $this->normalizeChunks(data_get($response, 'documents', []));
        if ($chunks !== []) {
            return $chunks;
        }

        $chunks = $this->normalizeChunks(data_get($response, 'passages', []));
        if ($chunks !== []) {
            return $chunks;
        }

        $results = data_get($response, 'results', []);
        if (! is_array($results)) {
            return [];
        }

        $collected = [];
        foreach ($results as $result) {
            if (! is_array($result)) {
                continue;
            }

            if (isset($result['chunks'])) {
                $collected = [...$collected, ...$this->normalizeChunks($result['chunks'])];
            }

            if (isset($result['documents'])) {
                $collected = [...$collected, ...$this->normalizeChunks($result['documents'])];
            }
        }

        return array_values(array_map(
            static fn (array $chunk, int $offset): array => [
                ...$chunk,
                'index' => $offset + 1,
            ],
            $collected,
            array_keys($collected),
        ));
    }

    /**
     * @return array<int, array{index: int, source_uuid: string|null, token_count: int|null, text: string}>
     */
    private function normalizeChunks(mixed $rawChunks): array
    {
        if (! is_array($rawChunks)) {
            return [];
        }

        if (! array_is_list($rawChunks)) {
            if (array_key_exists('text', $rawChunks) || array_key_exists('chunk', $rawChunks) || array_key_exists('content', $rawChunks)) {
                $rawChunks = [$rawChunks];
            } else {
                $rawChunks = array_values($rawChunks);
            }
        }

        $normalized = [];
        foreach ($rawChunks as $offset => $chunk) {
            if (is_string($chunk)) {
                $text = trim($chunk);
                if ($text === '') {
                    continue;
                }

                $normalized[] = [
                    'index' => $offset + 1,
                    'source_uuid' => null,
                    'token_count' => null,
                    'text' => $text,
                ];

                continue;
            }

            if (! is_array($chunk)) {
                continue;
            }

            $text = trim((string) ($chunk['text'] ?? $chunk['chunk'] ?? $chunk['content'] ?? ''));
            if ($text === '') {
                continue;
            }

            $sourceUuid = $chunk['source_uuid'] ?? $chunk['source_id'] ?? null;
            $tokenCount = $chunk['token_count'] ?? $chunk['tokens'] ?? null;

            $normalized[] = [
                'index' => $offset + 1,
                'source_uuid' => is_string($sourceUuid) && $sourceUuid !== '' ? $sourceUuid : null,
                'token_count' => is_numeric($tokenCount) ? (int) $tokenCount : null,
                'text' => $text,
            ];
        }

        return $normalized;
    }

    /**
     * @param  array<int, array{source_uuid: string, filename: string, text: string}>  $sourceInputs
     * @return array<int, string>
     */
    private function buildDocuments(array $sourceInputs): array
    {
        $documents = [];
        foreach ($sourceInputs as $sourceInput) {
            $text = trim((string) ($sourceInput['text'] ?? ''));
            if ($text === '') {
                continue;
            }

            $sourceUuid = (string) ($sourceInput['source_uuid'] ?? '');
            $prefix = sprintf('[SOURCE_ID:%s] ', $sourceUuid);
            $documents[] = $prefix.$text;
        }

        return $documents;
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

    /**
     * @param  array<string, mixed>  $payload
     */
    private function shouldRetryWithoutSourceRegistry(string $mode, Throwable $throwable, array $payload): bool
    {
        if ($mode !== 'index') {
            return false;
        }

        if (! isset($payload['sources']) || ! is_array($payload['sources']) || $payload['sources'] === []) {
            return false;
        }

        return Str::contains(
            Str::lower($throwable->getMessage()),
            'redis package is required for source uuid registry'
        );
    }
}
