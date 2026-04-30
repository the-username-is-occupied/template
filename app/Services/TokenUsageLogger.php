<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\TokenUsage;
use Laravel\Ai\Responses\AgentResponse;

class TokenUsageLogger
{
    public function __construct(private readonly WikiConfig $wikiConfig) {}

    /**
     * Log token usage from an agent response.
     *
     * @param  array{userspace: string, operation: string, provider: string, model: string, source_file?: string|null}  $context
     */
    public function log(AgentResponse $response, array $context): void
    {
        $inputTokens = $response->usage->promptTokens;
        $outputTokens = $response->usage->completionTokens;
        $model = $context['model'];

        $costs = $this->wikiConfig->calculateCost($model, $inputTokens, $outputTokens);

        TokenUsage::query()->create([
            'userspace' => $context['userspace'],
            'operation' => $context['operation'],
            'provider' => $context['provider'],
            'model' => $model,
            'input_tokens' => $inputTokens,
            'output_tokens' => $outputTokens,
            'input_cost' => $costs['input'],
            'output_cost' => $costs['output'],
            'total_cost' => $costs['total'],
            'source_file' => $context['source_file'] ?? null,
            'created_at' => now(),
        ]);
    }
}
