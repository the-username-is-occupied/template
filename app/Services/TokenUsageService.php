<?php

namespace App\Services;

use App\Models\TokenUsageLog;
use App\Models\UserSpace;

class TokenUsageService
{
    public function estimateTokens(string $text): int
    {
        return (int) ceil(mb_strlen($text) / 4);
    }

    public function estimateCost(string $model, int $promptTokens, int $completionTokens): float
    {
        $pricing = config("hipporag.token_pricing.{$model}", []);

        if ($pricing === []) {
            return 0.0;
        }

        return ($promptTokens / 1000 * (float) $pricing['prompt'])
            + ($completionTokens / 1000 * (float) $pricing['completion']);
    }

    public function log(
        ?UserSpace $userSpace,
        string $operationType,
        string $model,
        ?int $promptTokens,
        ?int $completionTokens,
        ?float $estimatedCostUsd = null,
    ): TokenUsageLog {
        $estimatedCostUsd ??= $this->estimateCost($model, $promptTokens ?? 0, $completionTokens ?? 0);

        return TokenUsageLog::query()->create([
            'user_space_id' => $userSpace?->id,
            'operation_type' => $operationType,
            'model_name' => $model,
            'prompt_tokens' => $promptTokens,
            'completion_tokens' => $completionTokens,
            'estimated_cost_usd' => $estimatedCostUsd,
        ]);
    }
}
