<?php

declare(strict_types=1);

use App\Models\UserSpace;
use App\Services\TokenUsageService;

it('estimates tokens from character length', function (): void {
    $service = new TokenUsageService;

    expect($service->estimateTokens('Hello world'))->toBe(3);
});

it('estimates cost from configured pricing', function (): void {
    $service = new TokenUsageService;

    expect($service->estimateCost('gpt-4o-mini', 1_000, 500))->toBe(0.00045);
});

it('logs token usage and estimated cost', function (): void {
    $service = new TokenUsageService;
    $space = UserSpace::factory()->create();

    $log = $service->log(
        userSpace: $space,
        operationType: 'query',
        model: 'deepseek-chat',
        promptTokens: 1_000,
        completionTokens: 1_000,
        estimatedCostUsd: null,
    );

    expect($log->user_space_id)->toBe($space->id)
        ->and($log->operation_type)->toBe('query')
        ->and((float) $log->estimated_cost_usd)->toBe(0.000042);
});
