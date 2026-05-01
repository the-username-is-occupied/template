<?php

declare(strict_types=1);

use App\Models\Source;
use App\Models\TokenUsageLog;
use App\Models\UserSpace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config()->set('hipporag.api_url', 'http://hipporag-api:8000');
    Storage::fake('local');
    Http::preventStrayRequests();
});

test('user can upload and index text file', function (): void {
    Http::fake([
        'hipporag-api:8000/index' => Http::response([
            'status' => 'success',
            'num_documents' => 1,
        ]),
    ]);

    $space = UserSpace::factory()->create();
    $file = UploadedFile::fake()->createWithContent('notes.txt', 'HippoRAG stores connected facts.');

    $this->post(route('hipporag.index-files'), [
        'user_space_id' => $space->id,
        'files' => [$file],
    ])->assertRedirect(route('hipporag.index', ['space' => $space->uuid]));

    $source = Source::query()->firstOrFail();

    expect($source->user_space_id)->toBe($space->id)
        ->and(TokenUsageLog::query()->where('operation_type', 'indexing')->exists())->toBeTrue();

    Storage::disk('local')->assertExists($source->storage_path);

    Http::assertSent(fn ($request): bool => str_contains((string) $request->url(), '/index')
        && $request['work_dir'] === $space->workDir()
        && str_starts_with($request['documents'][0], '[SOURCE_ID:'.$source->uuid.']'));
});

test('user can ask question rag mode', function (): void {
    $space = UserSpace::factory()->create();
    $source = Source::factory()->for($space)->create([
        'original_name' => 'notes.txt',
    ]);

    Http::fake([
        'hipporag-api:8000/query' => Http::response([
            'status' => 'success',
            'results' => [
                [
                    'question' => 'What is indexed?',
                    'answer' => 'Facts from [SOURCE_ID:'.$source->uuid.']',
                    'sources' => [
                        ['text' => '[SOURCE_ID:'.$source->uuid.'] HippoRAG stores connected facts.', 'score' => 0.95],
                    ],
                ],
            ],
        ]),
    ]);

    $response = $this->post(route('hipporag.query'), [
        'user_space_id' => $space->id,
        'questions' => 'What is indexed?',
        'mode' => 'rag',
        'num_to_retrieve' => 5,
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
                        ['text' => '[SOURCE_ID:'.$source->uuid.'] Retrieved text', 'score' => 0.92],
                    ],
                ],
            ],
        ]),
    ]);

    $response = $this->post(route('hipporag.query'), [
        'user_space_id' => $space->id,
        'questions' => 'Find facts',
        'mode' => 'retrieve',
        'num_to_retrieve' => 3,
    ])->assertRedirect(route('hipporag.index', ['space' => $space->uuid]));

    $results = $response->baseResponse->getSession()->get('last_operation')['results'];

    expect($results[0]['answer_html'])->toBeNull()
        ->and($results[0]['sources'][0]['text_html'])->toContain('Retrieved text');
});

test('multiple source ids are replaced correctly', function (): void {
    $space = UserSpace::factory()->create();
    $first = Source::factory()->for($space)->create(['original_name' => 'first.md']);
    $second = Source::factory()->for($space)->create(['original_name' => 'second.md']);

    Http::fake([
        'hipporag-api:8000/query' => Http::response([
            'status' => 'success',
            'results' => [
                [
                    'question' => 'Compare',
                    'answer' => '[SOURCE_ID:'.$first->uuid.'] and [SOURCE_ID:'.$second->uuid.']',
                    'sources' => [],
                ],
            ],
        ]),
    ]);

    $response = $this->post(route('hipporag.query'), [
        'user_space_id' => $space->id,
        'questions' => 'Compare',
        'mode' => 'rag',
        'num_to_retrieve' => 5,
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

    Http::fake([
        'hipporag-api:8000/query' => Http::response([
            'status' => 'success',
            'results' => [
                [
                    'question' => 'Cost?',
                    'answer' => 'Cost answer [SOURCE_ID:'.$source->uuid.']',
                    'sources' => [],
                ],
            ],
        ]),
    ]);

    $this->post(route('hipporag.query'), [
        'user_space_id' => $space->id,
        'questions' => 'Cost?',
        'mode' => 'rag',
        'num_to_retrieve' => 5,
    ]);

    $log = TokenUsageLog::query()->firstOrFail();

    expect($log->prompt_tokens)->toBeGreaterThan(0)
        ->and($log->completion_tokens)->toBeGreaterThan(0)
        ->and((float) $log->estimated_cost_usd)->toBeGreaterThan(0.0);
});
