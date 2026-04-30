<?php

declare(strict_types=1);

use App\Ai\Agents\IngestAgent;
use App\Models\TokenUsage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('wiki');
    Storage::disk('wiki')->makeDirectory('test-project/raw');
    Storage::disk('wiki')->makeDirectory('test-project/wiki');
    Storage::disk('wiki')->put('test-project/wiki/index.md', "# Индекс вики\n");
    Storage::disk('wiki')->put('test-project/wiki/log.md', "# Журнал операций\n");
    Storage::disk('wiki')->put('test-project/raw/article.md', '# Квантовые вычисления
Квантовый компьютер использует кубиты. Суперпозиция позволяет кубиту находиться в состоянии 0 и 1 одновременно.');
});

it('fails when raw file does not exist', function () {
    $this->artisan('wiki:ingest test-project missing.md')
        ->assertExitCode(1);
});

it('creates wiki pages after successful ingestion', function () {
    IngestAgent::fake([
        [
            'overall_summary' => 'Статья о квантовых вычислениях',
            'pages' => [
                [
                    'title' => 'Квантовые вычисления',
                    'slug' => 'quantum-computing',
                    'category' => 'concept',
                    'content' => 'Квантовые вычисления — способ вычислений на основе квантовых явлений.',
                    'linked_to' => ['qubit'],
                ],
                [
                    'title' => 'Кубит',
                    'slug' => 'qubit',
                    'category' => 'entity',
                    'content' => 'Кубит — основная единица квантовой информации.',
                    'linked_to' => ['quantum-computing'],
                ],
            ],
        ],
    ]);

    $this->artisan('wiki:ingest test-project article.md')
        ->assertExitCode(0);

    Storage::disk('wiki')->assertExists('test-project/wiki/quantum-computing.md');
    Storage::disk('wiki')->assertExists('test-project/wiki/qubit.md');
});

it('updates index.md after ingestion', function () {
    IngestAgent::fake([
        [
            'overall_summary' => 'Статья о квантовых вычислениях',
            'pages' => [
                [
                    'title' => 'Квантовые вычисления',
                    'slug' => 'quantum-computing',
                    'category' => 'concept',
                    'content' => 'Описание.',
                    'linked_to' => [],
                ],
            ],
        ],
    ]);

    $this->artisan('wiki:ingest test-project article.md')->assertExitCode(0);

    $index = Storage::disk('wiki')->get('test-project/wiki/index.md');
    expect($index)->toContain('[[quantum-computing]]');
});

it('updates log.md after ingestion', function () {
    IngestAgent::fake([
        [
            'overall_summary' => 'Summary',
            'pages' => [
                [
                    'title' => 'Test',
                    'slug' => 'test-page',
                    'category' => 'concept',
                    'content' => 'Content.',
                    'linked_to' => [],
                ],
            ],
        ],
    ]);

    $this->artisan('wiki:ingest test-project article.md')->assertExitCode(0);

    $log = Storage::disk('wiki')->get('test-project/wiki/log.md');
    expect($log)->toContain('ingest')->toContain('article.md');
});

it('logs token usage to database', function () {
    IngestAgent::fake([
        [
            'overall_summary' => 'Summary',
            'pages' => [
                [
                    'title' => 'Test',
                    'slug' => 'test-page',
                    'category' => 'concept',
                    'content' => 'Content.',
                    'linked_to' => [],
                ],
            ],
        ],
    ]);

    $this->artisan('wiki:ingest test-project article.md')->assertExitCode(0);

    $this->assertDatabaseHas('token_usages', [
        'userspace' => 'test-project',
        'operation' => 'ingest',
        'source_file' => 'article.md',
    ]);
});

it('uses production models when --production flag is set', function () {
    IngestAgent::fake([
        [
            'overall_summary' => 'Summary',
            'pages' => [],
        ],
    ]);

    $this->artisan('wiki:ingest test-project article.md --production')->assertExitCode(0);

    $usage = TokenUsage::query()->latest('id')->first();
    expect($usage->model)->toBe(config('wiki.production_model', 'gpt-4o'));
});

it('creates wiki page with proper frontmatter', function () {
    IngestAgent::fake([
        [
            'overall_summary' => 'Summary',
            'pages' => [
                [
                    'title' => 'Test Page',
                    'slug' => 'test-page',
                    'category' => 'entity',
                    'content' => 'Content here.',
                    'linked_to' => [],
                ],
            ],
        ],
    ]);

    $this->artisan('wiki:ingest test-project article.md')->assertExitCode(0);

    $content = Storage::disk('wiki')->get('test-project/wiki/test-page.md');

    expect($content)
        ->toContain('---')
        ->toContain('title: "Test Page"')
        ->toContain('category: entity')
        ->toContain('## Связанные заметки');
});
