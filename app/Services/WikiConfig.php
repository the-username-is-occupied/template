<?php

declare(strict_types=1);

namespace App\Services;

class WikiConfig
{
    /**
     * Pricing map per million tokens: [input_per_million, output_per_million]
     *
     * @var array<string, array{input: float, output: float}>
     */
    private array $pricingMap = [
        'gpt-4o-mini' => ['input' => 0.15, 'output' => 0.60],
        'gpt-4o' => ['input' => 2.50, 'output' => 10.00],
        'claude-haiku-4-5-20251001' => ['input' => 1.00, 'output' => 5.00],
    ];

    /**
     * @return array{provider: string, model: string}
     */
    public function getTestingProvider(): array
    {
        return [
            'provider' => config('wiki.testing_provider', 'openai'),
            'model' => config('wiki.testing_model', 'gpt-4o-mini'),
        ];
    }

    /**
     * @return array{provider: string, model: string}
     */
    public function getProductionProvider(): array
    {
        return [
            'provider' => config('wiki.production_provider', 'openai'),
            'model' => config('wiki.production_model', 'gpt-4o'),
        ];
    }

    /**
     * @return array{provider: string, model: string}
     */
    public function getFallbackProvider(): array
    {
        return [
            'provider' => config('wiki.fallback_provider', 'anthropic'),
            'model' => config('wiki.fallback_model', 'claude-haiku-4-5-20251001'),
        ];
    }

    /**
     * Calculate the cost for the given model and token counts.
     *
     * @return array{input: float, output: float, total: float}
     */
    public function calculateCost(string $model, int $inputTokens, int $outputTokens): array
    {
        $pricing = $this->pricingMap[$model] ?? ['input' => 0.0, 'output' => 0.0];

        $inputCost = ($inputTokens / 1_000_000) * $pricing['input'];
        $outputCost = ($outputTokens / 1_000_000) * $pricing['output'];

        return [
            'input' => round($inputCost, 6),
            'output' => round($outputCost, 6),
            'total' => round($inputCost + $outputCost, 6),
        ];
    }

    /**
     * @return array<string, array{input: float, output: float}>
     */
    public function getPricingMap(): array
    {
        return $this->pricingMap;
    }
}
