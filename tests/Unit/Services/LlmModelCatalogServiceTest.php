<?php

declare(strict_types=1);

use App\Services\LlmModelCatalogService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    Cache::flush();
    Http::preventStrayRequests();
});

it('uses freellmapi internal url when localhost url is configured in docker', function (): void {
    Config::set('hipporag.model_catalog_cache_seconds', 30);
    Config::set('ai.providers.freellmapi.url', 'http://localhost:3001/v1');
    Config::set('ai.providers.freellmapi.key', 'test-key');
    Config::set('services.freellmapi.internal_url', 'http://freellmapi:3001/v1');

    Http::fake([
        'freellmapi:3001/v1/models' => Http::response([
            'data' => [
                ['id' => 'gpt-4o-mini'],
            ],
        ]),
        'api.openai.com/v1/models' => Http::response([
            'data' => [],
        ]),
    ]);

    $result = app(LlmModelCatalogService::class)->models();

    expect($result['warnings'])->toBe([])
        ->and($result['models'])->toContain([
            'name' => 'gpt-4o-mini',
            'provider' => 'freellmapi',
        ]);

    Http::assertSent(fn ($request): bool => $request->url() === 'http://freellmapi:3001/v1/models');
});
