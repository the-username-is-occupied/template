<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Source;
use App\Models\TokenUsageLog;
use App\Models\UserSpace;
use Illuminate\Support\Collection;
use RuntimeException;

class HippoRAGQueryService
{
    public function __construct(
        private readonly HippoRAGClient $client,
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
     *     referenced_sources: Collection<int, Source>
     * }
     */
    public function query(UserSpace $userSpace, array $queries, string $mode, int $numToRetrieve): array
    {
        $model = (string) config('hipporag.default_model');
        $startedAt = microtime(true);
        $response = $this->client->query([
            'work_dir' => $userSpace->workDir(),
            'queries' => $queries,
            'mode' => $mode,
            'num_to_retrieve' => $numToRetrieve,
            'llm_model' => $model,
            'embedding_model' => (string) config('hipporag.default_embedding_model'),
            'llm_base_url' => config('hipporag.llm_base_url'),
            'embedding_base_url' => config('hipporag.embedding_base_url'),
            'llm_api_key' => config('hipporag.llm_api_key'),
        ]);

        $results = $this->normalizeResults($response['results'] ?? []);
        $rendered = [];
        $sourceUuids = [];
        $completionText = '';

        foreach ($results as $result) {
            if ($mode === 'rag') {
                $answer = (string) ($result['answer'] ?? '');
                $renderedAnswer = $this->sourceIdRenderer->render($answer);
                $sourceUuids = array_merge($sourceUuids, $renderedAnswer['source_uuids']);
                $completionText .= $answer."\n";

                $sources = [];
                foreach ($result['sources'] ?? [] as $source) {
                    $text = (string) ($source['text'] ?? '');
                    $renderedSourceText = $this->sourceIdRenderer->render($text);
                    $sourceUuids = array_merge($sourceUuids, $renderedSourceText['source_uuids']);
                    $completionText .= $text."\n";
                    $sources[] = [
                        'text' => $text,
                        'text_html' => (string) $renderedSourceText['html'],
                        'score' => $source['score'] ?? null,
                    ];
                }

                $rendered[] = [
                    'question' => (string) ($result['question'] ?? $result['query'] ?? ''),
                    'answer' => $answer,
                    'answer_html' => (string) $renderedAnswer['html'],
                    'sources' => $sources,
                ];

                continue;
            }

            $documents = [];
            foreach ($result['documents'] ?? [] as $document) {
                $text = (string) ($document['text'] ?? '');
                $renderedDocumentText = $this->sourceIdRenderer->render($text);
                $sourceUuids = array_merge($sourceUuids, $renderedDocumentText['source_uuids']);
                $completionText .= $text."\n";
                $documents[] = [
                    'text' => $text,
                    'text_html' => (string) $renderedDocumentText['html'],
                    'score' => $document['score'] ?? null,
                ];
            }

            $rendered[] = [
                'question' => (string) ($result['query'] ?? $result['question'] ?? ''),
                'answer_html' => null,
                'sources' => $documents,
            ];
        }

        $promptTokens = $this->tokenUsage->estimateTokens(implode("\n", $queries));
        $completionTokens = $this->tokenUsage->estimateTokens($completionText);
        $estimatedCost = $this->tokenUsage->estimateCost($model, $promptTokens, $completionTokens);

        TokenUsageLog::query()->create([
            'user_space_id' => $userSpace->id,
            'operation_type' => 'query',
            'model_name' => $model,
            'prompt_tokens' => $promptTokens,
            'completion_tokens' => $completionTokens,
            'estimated_cost_usd' => $estimatedCost,
        ]);

        return [
            'results' => $rendered,
            'mode' => $mode,
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
}
