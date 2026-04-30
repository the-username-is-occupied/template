<?php

declare(strict_types=1);

use App\Models\TokenUsage;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    TokenUsage::query()->create([
        'userspace' => 'test-project',
        'operation' => 'ingest',
        'provider' => 'openai',
        'model' => 'gpt-4o-mini',
        'input_tokens' => 1000,
        'output_tokens' => 500,
        'input_cost' => 0.000150,
        'output_cost' => 0.000300,
        'total_cost' => 0.000450,
        'source_file' => 'article.md',
        'created_at' => now(),
    ]);

    TokenUsage::query()->create([
        'userspace' => 'test-project',
        'operation' => 'query',
        'provider' => 'openai',
        'model' => 'gpt-4o-mini',
        'input_tokens' => 200,
        'output_tokens' => 800,
        'input_cost' => 0.000030,
        'output_cost' => 0.000480,
        'total_cost' => 0.000510,
        'source_file' => null,
        'created_at' => now(),
    ]);
});

it('shows aggregated report for all periods', function () {
    $this->artisan('wiki:tokens:report test-project --period=all')
        ->expectsOutputToContain('ingest')
        ->expectsOutputToContain('query')
        ->assertExitCode(0);
});

it('shows totals line', function () {
    $this->artisan('wiki:tokens:report test-project')
        ->expectsOutputToContain('Итого')
        ->assertExitCode(0);
});

it('supports --by-model grouping', function () {
    $this->artisan('wiki:tokens:report test-project --by-model')
        ->expectsOutputToContain('gpt-4o-mini')
        ->assertExitCode(0);
});

it('shows only records for the given userspace', function () {
    TokenUsage::query()->create([
        'userspace' => 'other-project',
        'operation' => 'ingest',
        'provider' => 'openai',
        'model' => 'gpt-4o',
        'input_tokens' => 9999,
        'output_tokens' => 9999,
        'input_cost' => 1.0,
        'output_cost' => 1.0,
        'total_cost' => 2.0,
        'source_file' => null,
        'created_at' => now(),
    ]);

    $this->artisan('wiki:tokens:report test-project')
        ->expectsOutputToContain('2 запросов')
        ->assertExitCode(0);
});
