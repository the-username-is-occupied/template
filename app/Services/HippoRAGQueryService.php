<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\UserSpace;
use RuntimeException;

class HippoRAGQueryService
{
    public function __construct(
        private readonly HippoRAGClient $client,
        private readonly HippoRAGAgentService $agentService,
        private readonly HippoRAGConnectionConfig $connectionConfig,
        private readonly TokenUsageService $tokenUsage,
        private readonly SourceIdRenderer $sourceIdRenderer,
    ) {}

    /**
     * @param  array<int, string>  $queries
     * @return array{
     *     results: array<int, array<string, mixed>>,
     *     prompt_tokens: int,
     *     completion_tokens: int,
     *     estimated_cost_usd: float,
     *     referenced_sources: array<int, array{uuid: string, filename: string, url: string}>
     * }
     */
    public function query(
        UserSpace $userSpace,
        array $queries,
        string $mode,
        int $numToRetrieve,
        string $llmModelName,
        ?string $llmProvider,
        float $scoreThreshold,
        ?string $agentInstructions,
    ): array {
        $startedAt = microtime(true);
        $response = $this->client->query([
            'work_dir' => $userSpace->workDir(),
            'queries' => $queries,
            'mode' => 'retrieve',
            'num_to_retrieve' => $numToRetrieve,
            'llm_model' => $llmModelName,
            'embedding_model' => (string) config('hipporag.default_embedding_model'),
            'score_threshold' => $scoreThreshold,
            ...$this->connectionConfig->build($llmModelName, $llmProvider),
        ]);

        $retrievalResults = $this->normalizeResults($this->extractResults($response));
        $documentsByQuery = [];
        foreach ($retrievalResults as $result) {
            $documentsByQuery[] = $this->normalizeDocuments($this->extractDocumentsPayload($result));
        }

        $answers = [];
        $agentUsage = ['prompt_tokens' => 0, 'completion_tokens' => 0, 'total_tokens' => 0];
        if ($mode === 'rag') {
            $agentResult = $this->agentService->answer(
                queries: $queries,
                documentsByQuery: $documentsByQuery,
                modelName: $llmModelName,
                userInstructions: $agentInstructions,
            );
            $answers = $agentResult['answers'];
            $agentUsage = $agentResult['token_usage'];
        }

        $rendered = [];
        $sourceUuids = [];
        foreach ($retrievalResults as $index => $result) {
            $documents = [];
            foreach ($documentsByQuery[$index] ?? [] as $document) {
                $text = (string) $document['text'];
                $renderedDocumentText = $this->sourceIdRenderer->render($text);
                $sourceUuids = array_merge($sourceUuids, $renderedDocumentText['source_uuids']);
                $documents[] = [
                    'text' => $text,
                    'text_html' => (string) $renderedDocumentText['html'],
                    'score' => $document['score'],
                    'source_uuid' => $document['source_uuid'],
                ];
            }

            $answer = $mode === 'rag' ? (string) ($answers[$index] ?? '') : '';
            $renderedAnswer = $mode === 'rag'
                ? $this->sourceIdRenderer->render($answer)
                : ['html' => null, 'source_uuids' => []];
            $sourceUuids = array_merge($sourceUuids, (array) $renderedAnswer['source_uuids']);

            $rendered[] = [
                'question' => (string) ($result['query'] ?? $result['question'] ?? ''),
                'answer' => $answer,
                'answer_html' => $mode === 'rag' ? (string) $renderedAnswer['html'] : null,
                'sources' => $documents,
            ];
        }

        $retrievalPromptTokens = (int) data_get($response, 'token_usage.prompt_tokens', 0);
        $retrievalCompletionTokens = (int) data_get($response, 'token_usage.completion_tokens', 0);
        $promptTokens = $retrievalPromptTokens + (int) $agentUsage['prompt_tokens'];
        $completionTokens = $retrievalCompletionTokens + (int) $agentUsage['completion_tokens'];
        $estimatedCost = $this->tokenUsage->estimateCost($llmModelName, $promptTokens, $completionTokens);
        $this->tokenUsage->log(
            userSpace: $userSpace,
            operationType: 'query',
            model: $llmModelName,
            promptTokens: $promptTokens,
            completionTokens: $completionTokens,
            estimatedCostUsd: $estimatedCost,
        );

        return [
            'results' => $rendered,
            'mode' => $mode,
            'score_threshold' => $scoreThreshold,
            'llm_model_name' => $llmModelName,
            'prompt_tokens' => $promptTokens,
            'completion_tokens' => $completionTokens,
            'estimated_cost_usd' => $estimatedCost,
            'response_time_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            'referenced_sources' => $this->sourceIdRenderer->sourceLinks($sourceUuids),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function normalizeResults(mixed $results): array
    {
        if (! is_array($results)) {
            throw new RuntimeException('HippoRAG API response is missing results.');
        }

        if (array_is_list($results)) {
            return $results;
        }

        return [$results];
    }

    /**
     * @return array<int, array{text: string, score: float|int|null, source_uuid: string|null}>
     */
    private function normalizeDocuments(mixed $documents): array
    {
        if (! is_array($documents)) {
            return [];
        }

        if (! array_is_list($documents)) {
            if ($this->isDocumentShape($documents)) {
                $documents = [$documents];
            } else {
                $documents = array_values($documents);
            }
        }

        $normalized = array_map(function (mixed $document): array {
            $sourceUuid = null;
            $score = null;
            $text = '';

            if (is_string($document)) {
                $text = trim($document);
            }

            if (is_array($document)) {
                $text = $this->extractDocumentText($document);
                $score = $this->extractDocumentScore($document);
                $sourceUuid = $this->extractDocumentSourceUuid($document);
            }

            if ($text === '') {
                return [
                    'text' => '',
                    'score' => null,
                    'source_uuid' => null,
                ];
            }

            if ($sourceUuid !== null) {
                $text = '[SOURCE_ID:'.$sourceUuid.'] '.$text;
            }

            return [
                'text' => $text,
                'score' => is_numeric($score) ? (float) $score : null,
                'source_uuid' => $sourceUuid,
            ];
        }, $documents);

        return array_values(array_filter($normalized, static fn (array $row): bool => $row['text'] !== ''));
    }

    private function normalizeSourceUuid(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : strtolower($trimmed);
    }

    /**
     * @param  array<string, mixed>  $response
     */
    private function extractResults(array $response): mixed
    {
        $candidates = [
            $response['results'] ?? null,
            $response['data'] ?? null,
            $response['retrieval_results'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            if (is_array($candidate)) {
                return $candidate;
            }
        }

        return [];
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function extractDocumentsPayload(array $result): mixed
    {
        $candidates = [
            $result['documents'] ?? null,
            $result['passages'] ?? null,
            $result['chunks'] ?? null,
            $result['ctxs'] ?? null,
            $result['contexts'] ?? null,
            $result['retrieved_documents'] ?? null,
            $result['sources'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            if (is_array($candidate)) {
                return $candidate;
            }
        }

        return [];
    }

    /**
     * @param  array<string, mixed>  $document
     */
    private function extractDocumentText(array $document): string
    {
        $value = $document['text']
            ?? $document['chunk']
            ?? $document['content']
            ?? $document['passage']
            ?? $document['document']
            ?? $document['ctx']
            ?? $document['context']
            ?? $document['snippet']
            ?? null;

        if (is_array($value)) {
            $value = $value['text'] ?? $value['content'] ?? null;
        }

        return trim((string) ($value ?? ''));
    }

    /**
     * @param  array<string, mixed>  $document
     */
    private function extractDocumentScore(array $document): float|int|null
    {
        $score = $document['score']
            ?? $document['retrieval_score']
            ?? $document['similarity']
            ?? $document['rank_score']
            ?? null;

        return is_numeric($score) ? (float) $score : null;
    }

    /**
     * @param  array<string, mixed>  $document
     */
    private function extractDocumentSourceUuid(array $document): ?string
    {
        $directValue = $document['source_uuid']
            ?? $document['source_id']
            ?? $document['uuid']
            ?? null;
        $sourceUuid = $this->normalizeSourceUuid($directValue);
        if ($sourceUuid !== null) {
            return $sourceUuid;
        }

        $metaCandidates = [
            data_get($document, 'metadata.source_uuid'),
            data_get($document, 'metadata.source_id'),
            data_get($document, 'meta.source_uuid'),
            data_get($document, 'meta.source_id'),
        ];

        foreach ($metaCandidates as $candidate) {
            $sourceUuid = $this->normalizeSourceUuid($candidate);
            if ($sourceUuid !== null) {
                return $sourceUuid;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $document
     */
    private function isDocumentShape(array $document): bool
    {
        return isset($document['text'])
            || isset($document['chunk'])
            || isset($document['content'])
            || isset($document['passage'])
            || isset($document['document'])
            || isset($document['ctx'])
            || isset($document['context']);
    }
}
