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
        float $scoreThreshold,
        ?string $agentInstructions,
    ): array {
        $startedAt = microtime(true);
        $response = $this->client->query([
            'work_dir' => $userSpace->workDir(),
            'queries' => $queries,
            'mode' => 'retrieve',
            'num_to_retrieve' => $numToRetrieve,
            'llm_model_name' => $llmModelName,
            'score_threshold' => $scoreThreshold,
        ]);

        $retrievalResults = $this->normalizeResults($response['results'] ?? []);
        $documentsByQuery = [];
        foreach ($retrievalResults as $result) {
            $documentsByQuery[] = $this->normalizeDocuments($result['documents'] ?? []);
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

        $normalized = array_map(function (mixed $document): array {
            $sourceUuid = null;
            $score = null;
            $text = '';

            if (is_array($document)) {
                $text = trim((string) ($document['text'] ?? ''));
                $score = $document['score'] ?? null;
                $sourceUuid = $this->normalizeSourceUuid($document['source_uuid'] ?? null);
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
}
