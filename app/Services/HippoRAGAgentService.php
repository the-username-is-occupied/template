<?php

declare(strict_types=1);

namespace App\Services;

use RuntimeException;
use Throwable;

use function Laravel\Ai\agent;

class HippoRAGAgentService
{
    public function __construct(
        private readonly LlmModelCatalogService $modelCatalog,
    ) {}

    /**
     * @param  array<int, string>  $queries
     * @param  array<int, array<int, array{text: string, score: float|int|null, source_uuid: string|null}>>  $documentsByQuery
     * @return array{
     *     answers: array<int, string>,
     *     provider: string,
     *     token_usage: array{prompt_tokens: int, completion_tokens: int, total_tokens: int}
     * }
     */
    public function answer(
        array $queries,
        array $documentsByQuery,
        string $modelName,
        ?string $userInstructions = null,
    ): array {
        $provider = $this->modelCatalog->resolveProviderForModel($modelName);
        $instructions = $this->instructions($userInstructions);
        $answers = [];
        $promptTokens = 0;
        $completionTokens = 0;

        foreach ($queries as $index => $query) {
            $documents = $documentsByQuery[$index] ?? [];
            $prompt = $this->buildPrompt($query, $documents);

            try {
                $response = agent(
                    instructions: $instructions,
                    messages: [],
                    tools: [],
                )->prompt(
                    $prompt,
                    provider: $provider,
                    model: $modelName,
                );
            } catch (Throwable $throwable) {
                throw new RuntimeException('Laravel AI Ask Agent request failed: '.$throwable->getMessage(), 0, $throwable);
            }

            $answers[] = trim((string) $response);
            $usage = $this->extractUsage($response);
            $promptTokens += $usage['prompt_tokens'];
            $completionTokens += $usage['completion_tokens'];
        }

        return [
            'answers' => $answers,
            'provider' => $provider,
            'token_usage' => [
                'prompt_tokens' => $promptTokens,
                'completion_tokens' => $completionTokens,
                'total_tokens' => $promptTokens + $completionTokens,
            ],
        ];
    }

    /**
     * @param  array<int, array{text: string, score: float|int|null, source_uuid: string|null}>  $documents
     */
    private function buildPrompt(string $query, array $documents): string
    {
        $contextRows = [];
        foreach ($documents as $index => $document) {
            $sourceUuid = trim((string) ($document['source_uuid'] ?? ''));
            $prefix = $sourceUuid !== '' ? "[SOURCE_ID:{$sourceUuid}] " : '';
            $score = $document['score'];
            $scoreText = $score !== null ? sprintf(' (score: %.3f)', (float) $score) : '';
            $contextRows[] = sprintf(
                '%d. %s%s%s',
                $index + 1,
                $prefix,
                trim((string) ($document['text'] ?? '')),
                $scoreText
            );
        }

        $context = $contextRows === [] ? 'No retrieved context.' : implode("\n\n", $contextRows);

        return implode("\n\n", [
            'Question:',
            $query,
            'Retrieved context:',
            $context,
            'Answer in Russian. Keep it concise and include [SOURCE_ID:...] markers when citing evidence.',
        ]);
    }

    private function instructions(?string $userInstructions): string
    {
        $defaultInstructions = trim((string) config('hipporag.agent.default_instructions'));
        $userInstructions = trim((string) $userInstructions);

        if ($userInstructions === '') {
            return $defaultInstructions;
        }

        return $defaultInstructions."\n\n".$userInstructions;
    }

    /**
     * @return array{prompt_tokens: int, completion_tokens: int}
     */
    private function extractUsage(mixed $response): array
    {
        $usage = null;
        if (is_array($response)) {
            $usage = $response['usage'] ?? null;
        } elseif (is_object($response)) {
            /** @var mixed $usage */
            $usage = $response->usage ?? null;
        }

        return [
            'prompt_tokens' => $this->readUsageField($usage, 'prompt_tokens'),
            'completion_tokens' => max(
                $this->readUsageField($usage, 'completion_tokens'),
                $this->readUsageField($usage, 'output_tokens'),
            ),
        ];
    }

    private function readUsageField(mixed $usage, string $field): int
    {
        if ($usage === null) {
            return 0;
        }

        if (is_array($usage)) {
            return (int) ($usage[$field] ?? 0);
        }

        if (is_object($usage)) {
            return (int) ($usage->{$field} ?? 0);
        }

        return 0;
    }
}
