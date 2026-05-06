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
        && $request['llm_model_name'] === 'gpt-4o-mini'
        && $request['mode'] === 'index'
        && ($request['sources'][0]['source_uuid'] ?? null) === $source->uuid);
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
