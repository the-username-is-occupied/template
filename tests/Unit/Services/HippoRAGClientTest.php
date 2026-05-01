<?php

declare(strict_types=1);

use App\Services\HippoRAGClient;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;

it('posts index requests to hipporag api', function () {
    Config::set('hipporag.api_url', 'http://hipporag-api:8000');
    Http::fake([
        'hipporag-api:8000/index' => Http::response([
            'status' => 'success',
            'num_documents' => 1,
        ]),
    ]);

    $response = app(HippoRAGClient::class)->index([
        'work_dir' => '/data/userspace_abc',
        'documents' => ['Document'],
    ]);

    expect($response['num_documents'])->toBe(1);
    Http::assertSent(fn ($request): bool => $request->url() === 'http://hipporag-api:8000/index'
        && $request['work_dir'] === '/data/userspace_abc'
        && $request['documents'] === ['Document']);
});

it('throws when hipporag returns an error payload', function () {
    Config::set('hipporag.api_url', 'http://hipporag-api:8000');
    Http::fake([
        'hipporag-api:8000/query' => Http::response([
            'status' => 'error',
            'detail' => 'OpenAI API key missing',
        ]),
    ]);

    app(HippoRAGClient::class)->query([
        'work_dir' => '/data/userspace_abc',
        'queries' => ['Question?'],
    ]);
})->throws(RuntimeException::class, 'OpenAI API key missing');
