<?php

declare(strict_types=1);

use App\Models\Source;
use App\Models\TokenUsageLog;
use App\Models\UserSpace;
use App\Services\HippoRAGAgentService;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Mockery\MockInterface;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->withoutMiddleware(ValidateCsrfToken::class);
    config()->set('hipporag.api_url', 'http://hipporag-api:8000');
    config()->set('hipporag.default_model', 'gpt-4o-mini');
    config()->set('hipporag.default_embedding_model', 'text-embedding-3-small');
    config()->set('hipporag.embedding_base_url', 'https://api.openai.com/v1');
    config()->set('hipporag.embedding_api_key', 'openai-key');
    config()->set('ai.providers.freellmapi.url', 'http://freellmapi:3001/v1');
    config()->set('ai.providers.freellmapi.key', 'freellmapi-key');
    config()->set('ai.providers.openai.url', 'https://api.openai.com/v1');
    config()->set('ai.providers.openai.key', 'openai-chat-key');
    Storage::fake('local');
    Http::preventStrayRequests();
});

test('user can upload and index text file', function (): void {
    Http::fake([
        'hipporag-api:8000/index' => Http::response([
            'status' => 'success',
            'mode' => 'index',
            'num_sources' => 1,
            'num_chunks' => 1,
            'token_usage' => [
                'prompt_tokens' => 20,
                'completion_tokens' => 0,
            ],
        ]),
    ]);

    $space = UserSpace::factory()->create();
    $file = UploadedFile::fake()->createWithContent('notes.txt', 'HippoRAG stores connected facts.');

    $this->post(route('hipporag.index-files'), [
        'user_space_id' => $space->id,
        'files' => [$file],
        'pasted_text' => '',
        'llm_model_name' => 'gpt-4o-mini',
        'index_mode' => 'index',
        'chunk_size' => 512,
        'overlap_ratio' => 0.12,
    ])->assertRedirect(route('hipporag.index', ['space' => $space->uuid]));

    $source = Source::query()->firstOrFail();

    expect($source->user_space_id)->toBe($space->id)
        ->and(TokenUsageLog::query()->where('operation_type', 'indexing')->exists())->toBeTrue();

    Storage::disk('local')->assertExists($source->storage_path);

    Http::assertSent(fn ($request): bool => str_contains((string) $request->url(), '/index')
        && $request['work_dir'] === $space->workDir()
        && $request['llm_model'] === 'gpt-4o-mini'
        && $request['llm_model_name'] === 'gpt-4o-mini'
        && $request['llm_base_url'] === 'http://freellmapi:3001/v1'
        && $request['llm_api_key'] === 'freellmapi-key'
        && $request['embedding_model'] === 'text-embedding-3-small'
        && $request['embedding_model_name'] === 'text-embedding-3-small'
        && $request['embedding_base_url'] === 'https://api.openai.com/v1'
        && $request['embedding_api_key'] === 'openai-key'
        && $request['mode'] === 'index'
        && ($request['sources'][0]['source_uuid'] ?? null) === $source->uuid
        && str_contains((string) ($request['documents'][0] ?? ''), '[SOURCE_ID:'.$source->uuid.']'));
});

test('index mode retries without sources when registry requires redis package', function (): void {
    $requests = [];
    $attempt = 0;

    Http::fake(function ($request) use (&$requests, &$attempt) {
        $requests[] = $request->data();
        $attempt++;

        if ($attempt === 1) {
            return Http::response([
                'status' => 'error',
                'detail' => 'redis package is required for source UUID registry',
            ]);
        }

        return Http::response([
            'status' => 'success',
            'mode' => 'index',
            'num_sources' => 1,
            'num_chunks' => 1,
            'token_usage' => [
                'prompt_tokens' => 20,
                'completion_tokens' => 0,
            ],
        ]);
    });

    $space = UserSpace::factory()->create();
    $file = UploadedFile::fake()->createWithContent('notes.txt', 'HippoRAG stores connected facts.');

    $response = $this->post(route('hipporag.index-files'), [
        'user_space_id' => $space->id,
        'files' => [$file],
        'pasted_text' => '',
        'llm_model_name' => 'gpt-4o-mini',
        'index_mode' => 'index',
        'chunk_size' => 512,
        'overlap_ratio' => 0.12,
    ]);

    $response->assertRedirect(route('hipporag.index', ['space' => $space->uuid]));
    $response->assertSessionHas('last_operation');

    $lastOperation = $response->baseResponse->getSession()->get('last_operation');

    expect($requests)->toHaveCount(2)
        ->and($requests[0]['sources'] ?? null)->toBeArray()
        ->and($requests[1]['sources'] ?? null)->toBeNull()
        ->and($lastOperation['warnings'] ?? [])->toHaveCount(1)
        ->and($lastOperation['warnings'][0] ?? null)->toContain('source UUID registry is unavailable');
});

test('index mode shows hipporag runtime errors without throwing 500', function (): void {
    Http::fake([
        'hipporag-api:8000/index' => Http::response([
            'status' => 'error',
            'detail' => 'division by zero',
        ]),
    ]);

    $space = UserSpace::factory()->create();
    $file = UploadedFile::fake()->createWithContent('broken.txt', 'Trigger index runtime error.');

    $response = $this->post(route('hipporag.index-files'), [
        'user_space_id' => $space->id,
        'files' => [$file],
        'pasted_text' => '',
        'llm_model_name' => 'gpt-4o-mini',
        'index_mode' => 'index',
        'chunk_size' => 512,
        'overlap_ratio' => 0.12,
    ]);

    $response->assertRedirect(route('hipporag.index', ['space' => $space->uuid]))
        ->assertSessionHasErrors('indexing');

    $errors = $response->baseResponse->getSession()->get('errors');

    expect($errors->first('indexing'))->toContain('division by zero');
});

test('chunk mode stores normalized chunks for preview list', function (): void {
    Http::fake([
        'hipporag-api:8000/index' => Http::response([
            'status' => 'success',
            'mode' => 'chunk',
            'chunks' => [
                [
                    'chunk' => 'First chunk text',
                    'tokens' => 42,
                    'source_id' => 'source-a',
                ],
                'Second chunk text',
            ],
            'token_usage' => [
                'prompt_tokens' => 12,
                'completion_tokens' => 0,
            ],
        ]),
    ]);

    $space = UserSpace::factory()->create();
    $file = UploadedFile::fake()->createWithContent('notes.txt', 'Chunk me please.');

    $response = $this->post(route('hipporag.index-files'), [
        'user_space_id' => $space->id,
        'files' => [$file],
        'pasted_text' => '',
        'llm_model_name' => 'gpt-4o-mini',
        'index_mode' => 'chunk',
        'chunk_size' => 512,
        'overlap_ratio' => 0.12,
    ]);

    $response->assertRedirect(route('hipporag.index', ['space' => $space->uuid]));
    $response->assertSessionHas('last_operation');

    $lastOperation = $response->baseResponse->getSession()->get('last_operation');

    expect($lastOperation['mode'])->toBe('chunk')
        ->and($lastOperation['chunks'])->toHaveCount(2)
        ->and($lastOperation['chunks'][0]['index'])->toBe(1)
        ->and($lastOperation['chunks'][0]['source_uuid'])->toBe('source-a')
        ->and($lastOperation['chunks'][0]['token_count'])->toBe(42)
        ->and($lastOperation['chunks'][0]['text'])->toBe('First chunk text')
        ->and($lastOperation['chunks'][1]['index'])->toBe(2)
        ->and($lastOperation['chunks'][1]['source_uuid'])->toBeNull()
        ->and($lastOperation['chunks'][1]['token_count'])->toBeNull()
        ->and($lastOperation['chunks'][1]['text'])->toBe('Second chunk text');
});

test('chunk mode can extract chunks from documents payload', function (): void {
    Http::fake([
        'hipporag-api:8000/index' => Http::response([
            'status' => 'success',
            'mode' => 'chunk',
            'documents' => [
                'Doc chunk one',
                ['content' => 'Doc chunk two', 'tokens' => 11],
            ],
            'token_usage' => [
                'prompt_tokens' => 9,
                'completion_tokens' => 0,
            ],
        ]),
    ]);

    $space = UserSpace::factory()->create();
    $file = UploadedFile::fake()->createWithContent('doc.txt', 'Chunk source.');

    $response = $this->post(route('hipporag.index-files'), [
        'user_space_id' => $space->id,
        'files' => [$file],
        'pasted_text' => '',
        'llm_model_name' => 'gpt-4o-mini',
        'index_mode' => 'chunk',
        'chunk_size' => 512,
        'overlap_ratio' => 0.12,
    ]);

    $lastOperation = $response->baseResponse->getSession()->get('last_operation');

    expect($lastOperation['mode'])->toBe('chunk')
        ->and($lastOperation['chunks'])->toHaveCount(2)
        ->and($lastOperation['chunks'][0]['text'])->toBe('Doc chunk one')
        ->and($lastOperation['chunks'][1]['text'])->toBe('Doc chunk two')
        ->and($lastOperation['chunks'][1]['token_count'])->toBe(11);
});

test('chunk mode keeps chunk list empty when api returns only metrics', function (): void {
    Http::fake([
        'hipporag-api:8000/index' => Http::response([
            'status' => 'success',
            'mode' => 'chunk',
            'num_chunks' => 1,
            'token_usage' => [
                'prompt_tokens' => 350,
                'completion_tokens' => 0,
            ],
        ]),
    ]);

    $space = UserSpace::factory()->create();
    $response = $this->post(route('hipporag.index-files'), [
        'user_space_id' => $space->id,
        'files' => [],
        'pasted_text' => str_repeat('Chunk fallback text. ', 200),
        'llm_model_name' => 'gpt-4o-mini',
        'index_mode' => 'chunk',
        'chunk_size' => 128,
        'overlap_ratio' => 0.12,
    ]);

    $lastOperation = $response->baseResponse->getSession()->get('last_operation');

    expect($lastOperation['mode'])->toBe('chunk')
        ->and($lastOperation['chunks'])->toBe([])
        ->and($lastOperation['warnings'] ?? [])->toBe([]);
});

test('chunk mode reports hipporag runtime error without local chunk fallback', function (): void {
    Http::fake([
        'hipporag-api:8000/index' => Http::response([
            'status' => 'error',
            'detail' => 'division by zero',
        ]),
    ]);

    $space = UserSpace::factory()->create();
    $response = $this->post(route('hipporag.index-files'), [
        'user_space_id' => $space->id,
        'files' => [],
        'pasted_text' => str_repeat('Local fallback chunk. ', 160),
        'llm_model_name' => 'gpt-4o-mini',
        'index_mode' => 'chunk',
        'chunk_size' => 128,
        'overlap_ratio' => 0.12,
    ]);

    $response->assertRedirect(route('hipporag.index', ['space' => $space->uuid]));
    $response->assertSessionHas('last_operation');
    $lastOperation = $response->baseResponse->getSession()->get('last_operation');

    expect($lastOperation['mode'])->toBe('chunk')
        ->and($lastOperation['chunks'])->toBe([])
        ->and($lastOperation['warnings'])->toHaveCount(1)
        ->and($lastOperation['warnings'][0])->toContain('division by zero');
});

test('index request sends source payload for hipporag chunking', function (): void {
    Http::fake([
        'hipporag-api:8000/index' => Http::response([
            'status' => 'success',
            'mode' => 'chunk',
            'chunks' => [],
            'token_usage' => [
                'prompt_tokens' => 250,
                'completion_tokens' => 0,
            ],
        ]),
    ]);

    $space = UserSpace::factory()->create();
    $largeText = str_repeat('Large payload for hipporag document splitting. ', 200);

    $this->post(route('hipporag.index-files'), [
        'user_space_id' => $space->id,
        'files' => [],
        'pasted_text' => $largeText,
        'llm_model_name' => 'gpt-4o-mini',
        'index_mode' => 'chunk',
        'chunk_size' => 512,
        'overlap_ratio' => 0.12,
    ]);

    Http::assertSent(function ($request) use ($largeText): bool {
        $documents = $request['documents'] ?? [];
        $sources = $request['sources'] ?? [];

        if (! is_array($documents) || count($documents) !== 1) {
            return false;
        }

        if (! is_array($sources) || count($sources) !== 1) {
            return false;
        }

        return str_contains((string) $documents[0], '[SOURCE_ID:')
            && ($sources[0]['text'] ?? null) === trim($largeText);
    });
});

test('user can ask question rag mode', function (): void {
    $space = UserSpace::factory()->create();
    $source = Source::factory()->for($space)->create([
        'original_name' => 'notes.txt',
    ]);

    $this->mock(HippoRAGAgentService::class, function (MockInterface $mock) use ($source): void {
        $mock->shouldReceive('answer')
            ->once()
            ->andReturn([
                'answers' => ['Facts from [SOURCE_ID:'.$source->uuid.']'],
                'provider' => 'openai',
                'token_usage' => [
                    'prompt_tokens' => 7,
                    'completion_tokens' => 11,
                    'total_tokens' => 18,
                ],
            ]);
    });

    Http::fake([
        'hipporag-api:8000/query' => Http::response([
            'status' => 'success',
            'results' => [
                [
                    'query' => 'What is indexed?',
                    'documents' => [
                        ['text' => 'HippoRAG stores connected facts.', 'score' => 0.95, 'source_uuid' => $source->uuid],
                    ],
                ],
            ],
            'token_usage' => [
                'prompt_tokens' => 3,
                'completion_tokens' => 2,
            ],
        ]),
    ]);

    $response = $this->post(route('hipporag.query'), [
        'user_space_id' => $space->id,
        'questions' => 'What is indexed?',
        'mode' => 'rag',
        'num_to_retrieve' => 5,
        'llm_model_name' => 'gpt-4o-mini',
        'score_threshold' => 0.4,
        'agent_instructions' => 'Answer with citations',
    ]);

    $response->assertRedirect(route('hipporag.index', ['space' => $space->uuid]));
    $response->assertSessionHas('last_operation');

    $results = $response->baseResponse->getSession()->get('last_operation')['results'];

    expect($results[0]['answer_html'])->toContain('notes.txt')
        ->and(TokenUsageLog::query()->where('operation_type', 'query')->exists())->toBeTrue();

    Http::assertSent(fn ($request): bool => str_contains((string) $request->url(), '/query')
        && $request['llm_model_name'] === 'gpt-4o-mini'
        && $request['llm_base_url'] === 'http://freellmapi:3001/v1'
        && $request['llm_api_key'] === 'freellmapi-key'
        && $request['embedding_model_name'] === 'text-embedding-3-small'
        && $request['embedding_base_url'] === 'https://api.openai.com/v1'
        && $request['embedding_api_key'] === 'openai-key');
});

test('query request uses openai key when provider is openai', function (): void {
    $space = UserSpace::factory()->create();

    Http::fake([
        'hipporag-api:8000/query' => Http::response([
            'status' => 'success',
            'results' => [
                [
                    'query' => 'OpenAI question',
                    'documents' => [],
                ],
            ],
            'token_usage' => [
                'prompt_tokens' => 1,
                'completion_tokens' => 0,
            ],
        ]),
    ]);

    $this->post(route('hipporag.query'), [
        'user_space_id' => $space->id,
        'questions' => 'OpenAI question',
        'mode' => 'retrieve',
        'num_to_retrieve' => 3,
        'llm_model_name' => 'openai::gpt-4o-mini',
        'score_threshold' => 0.4,
        'agent_instructions' => '',
    ])->assertRedirect(route('hipporag.index', ['space' => $space->uuid]));

    Http::assertSent(fn ($request): bool => str_contains((string) $request->url(), '/query')
        && $request['llm_model_name'] === 'gpt-4o-mini'
        && $request['llm_provider'] === 'openai'
        && $request['llm_base_url'] === 'https://api.openai.com/v1'
        && $request['llm_api_key'] === 'openai-chat-key');
});

test('retrieve mode returns sources without answer', function (): void {
    $space = UserSpace::factory()->create();
    $source = Source::factory()->for($space)->create();

    Http::fake([
        'hipporag-api:8000/query' => Http::response([
            'status' => 'success',
            'results' => [
                [
                    'query' => 'Find facts',
                    'documents' => [
                        ['text' => 'Retrieved text', 'score' => 0.92, 'source_uuid' => $source->uuid],
                    ],
                ],
            ],
            'token_usage' => [
                'prompt_tokens' => 2,
                'completion_tokens' => 1,
            ],
        ]),
    ]);

    $response = $this->post(route('hipporag.query'), [
        'user_space_id' => $space->id,
        'questions' => 'Find facts',
        'mode' => 'retrieve',
        'num_to_retrieve' => 3,
        'llm_model_name' => 'gpt-4o-mini',
        'score_threshold' => 0.4,
        'agent_instructions' => '',
    ])->assertRedirect(route('hipporag.index', ['space' => $space->uuid]));

    $results = $response->baseResponse->getSession()->get('last_operation')['results'];

    expect($results[0]['answer_html'])->toBeNull()
        ->and($results[0]['sources'][0]['text_html'])->toContain('Retrieved text');
});

test('retrieve mode supports passages payload shape from hipporag', function (): void {
    $space = UserSpace::factory()->create();
    $source = Source::factory()->for($space)->create();

    Http::fake([
        'hipporag-api:8000/query' => Http::response([
            'status' => 'success',
            'results' => [
                [
                    'query' => 'Find passages',
                    'passages' => [
                        [
                            'content' => 'Passage payload text',
                            'similarity' => 0.89,
                            'metadata' => [
                                'source_uuid' => $source->uuid,
                            ],
                        ],
                    ],
                ],
            ],
            'token_usage' => [
                'prompt_tokens' => 2,
                'completion_tokens' => 1,
            ],
        ]),
    ]);

    $response = $this->post(route('hipporag.query'), [
        'user_space_id' => $space->id,
        'questions' => 'Find passages',
        'mode' => 'retrieve',
        'num_to_retrieve' => 3,
        'llm_model_name' => 'gpt-4o-mini',
        'score_threshold' => 0.4,
        'agent_instructions' => '',
    ])->assertRedirect(route('hipporag.index', ['space' => $space->uuid]));

    $results = $response->baseResponse->getSession()->get('last_operation')['results'];

    expect($results[0]['sources'])->toHaveCount(1)
        ->and($results[0]['sources'][0]['text_html'])->toContain('Passage payload text')
        ->and($results[0]['sources'][0]['source_uuid'])->toBe($source->uuid)
        ->and($results[0]['sources'][0]['score'])->toBe(0.89);
});

test('multiple source ids are replaced correctly', function (): void {
    $space = UserSpace::factory()->create();
    $first = Source::factory()->for($space)->create(['original_name' => 'first.md']);
    $second = Source::factory()->for($space)->create(['original_name' => 'second.md']);

    $this->mock(HippoRAGAgentService::class, function (MockInterface $mock) use ($first, $second): void {
        $mock->shouldReceive('answer')
            ->once()
            ->andReturn([
                'answers' => ['[SOURCE_ID:'.$first->uuid.'] and [SOURCE_ID:'.$second->uuid.']'],
                'provider' => 'openai',
                'token_usage' => [
                    'prompt_tokens' => 5,
                    'completion_tokens' => 7,
                    'total_tokens' => 12,
                ],
            ]);
    });

    Http::fake([
        'hipporag-api:8000/query' => Http::response([
            'status' => 'success',
            'results' => [
                [
                    'query' => 'Compare',
                    'documents' => [
                        ['text' => 'Fact A', 'score' => 0.99, 'source_uuid' => $first->uuid],
                        ['text' => 'Fact B', 'score' => 0.98, 'source_uuid' => $second->uuid],
                    ],
                ],
            ],
            'token_usage' => [
                'prompt_tokens' => 3,
                'completion_tokens' => 1,
            ],
        ]),
    ]);

    $response = $this->post(route('hipporag.query'), [
        'user_space_id' => $space->id,
        'questions' => 'Compare',
        'mode' => 'rag',
        'num_to_retrieve' => 5,
        'llm_model_name' => 'gpt-4o-mini',
        'score_threshold' => 0.4,
        'agent_instructions' => '',
    ]);

    $answer = $response->baseResponse->getSession()->get('last_operation')['results'][0]['answer_html'];

    expect($answer)->toContain('first.md')
        ->and($answer)->toContain('second.md');
});

test('deleting space removes hipporag workdir and db records', function (): void {
    $space = UserSpace::factory()->create();
    Source::factory()->for($space)->create();

    Http::fake([
        'hipporag-api:8000/delete' => Http::response([
            'status' => 'success',
            'deleted' => true,
        ]),
    ]);

    $this->delete(route('hipporag.spaces.destroy', $space))->assertRedirect(route('hipporag.index'));

    $this->assertDatabaseMissing('user_spaces', ['id' => $space->id]);
    $this->assertDatabaseCount('sources', 0);

    Http::assertSent(fn ($request): bool => str_contains((string) $request->url(), '/delete')
        && $request['work_dir'] === $space->workDir());
});

test('token usage and cost are logged', function (): void {
    $space = UserSpace::factory()->create();
    $source = Source::factory()->for($space)->create();

    $this->mock(HippoRAGAgentService::class, function (MockInterface $mock) use ($source): void {
        $mock->shouldReceive('answer')
            ->once()
            ->andReturn([
                'answers' => ['Cost answer [SOURCE_ID:'.$source->uuid.']'],
                'provider' => 'openai',
                'token_usage' => [
                    'prompt_tokens' => 13,
                    'completion_tokens' => 17,
                    'total_tokens' => 30,
                ],
            ]);
    });

    Http::fake([
        'hipporag-api:8000/query' => Http::response([
            'status' => 'success',
            'results' => [
                [
                    'query' => 'Cost?',
                    'documents' => [
                        ['text' => 'Cost evidence', 'score' => 0.91, 'source_uuid' => $source->uuid],
                    ],
                ],
            ],
            'token_usage' => [
                'prompt_tokens' => 11,
                'completion_tokens' => 19,
            ],
        ]),
    ]);

    $this->post(route('hipporag.query'), [
        'user_space_id' => $space->id,
        'questions' => 'Cost?',
        'mode' => 'rag',
        'num_to_retrieve' => 5,
        'llm_model_name' => 'gpt-4o-mini',
        'score_threshold' => 0.4,
        'agent_instructions' => '',
    ]);

    $log = TokenUsageLog::query()->firstOrFail();

    expect($log->prompt_tokens)->toBeGreaterThan(0)
        ->and($log->completion_tokens)->toBeGreaterThan(0)
        ->and((float) $log->estimated_cost_usd)->toBeGreaterThan(0.0);
});
