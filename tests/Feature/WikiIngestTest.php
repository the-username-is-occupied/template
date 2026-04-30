<?php

declare(strict_types=1);

use App\Ai\Agents\IngestAgent;
use App\Models\TokenUsage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

/** @return array<string, mixed> */
function fakeIngestResponse(array $overrides = []): array
{
    return array_merge([
        'overall_summary' => 'Статья о квантовых вычислениях',
        'source_page' => [
            'title' => 'Обзор: article.md',
            'slug' => 'source-article',
            'content' => "## Основная идея\nКвантовые вычисления используют кубиты.\n\n## Ключевые выводы\n- Суперпозиция увеличивает скорость вычислений\n\n## Структура источника\nОдна глава.\n\n## Связь с другими темами\nФизика, CS.",
        ],
        'pages' => [
            [
                'title' => 'Квантовые вычисления',
                'slug' => 'quantum-computing',
                'category' => 'concept',
                'content' => "## Определение / Что это\nКвантовые вычисления — вычисления на основе квантовых явлений. [src]\n\n## Ключевая информация\nИспользуют кубиты.\n\n## Контекст и значение\nРешают задачи экспоненциальной сложности.\n\n## Связи\nСвязано с суперпозицией.\n\n## Цитаты и утверждения\n«Квантовое превосходство достигнуто в 2019» [src]",
                'linked_to' => [
                    ['slug' => 'qubit', 'relationship' => 'зависит от'],
                ],
            ],
            [
                'title' => 'Кубит',
                'slug' => 'qubit',
                'category' => 'entity',
                'content' => "## Определение / Что это\nКубит — основная единица квантовой информации. [src]\n\n## Ключевая информация\nМожет быть в суперпозиции.\n\n## Контекст и значение\nОсновной строительный блок квантового компьютера.\n\n## Связи\nЯвляется частью квантовых вычислений.\n\n## Цитаты и утверждения\n—",
                'linked_to' => [
                    ['slug' => 'quantum-computing', 'relationship' => 'часть'],
                ],
            ],
        ],
        'novel_claims' => [],
    ], $overrides);
}

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

it('creates the source overview page', function () {
    IngestAgent::fake([fakeIngestResponse()]);

    $this->artisan('wiki:ingest test-project article.md')->assertExitCode(0);

    Storage::disk('wiki')->assertExists('test-project/wiki/source-article.md');
});

it('creates entity and concept wiki pages', function () {
    IngestAgent::fake([fakeIngestResponse()]);

    $this->artisan('wiki:ingest test-project article.md')->assertExitCode(0);

    Storage::disk('wiki')->assertExists('test-project/wiki/quantum-computing.md');
    Storage::disk('wiki')->assertExists('test-project/wiki/qubit.md');
});

it('creates wiki pages with rich structured sections', function () {
    IngestAgent::fake([fakeIngestResponse()]);

    $this->artisan('wiki:ingest test-project article.md')->assertExitCode(0);

    $content = Storage::disk('wiki')->get('test-project/wiki/quantum-computing.md');

    expect($content)
        ->toContain('## Определение')
        ->toContain('## Ключевая информация')
        ->toContain('## Контекст и значение')
        ->toContain('## Связанные заметки')
        ->toContain('[[qubit]]')
        ->toContain('зависит от');
});

it('creates wiki page with proper frontmatter', function () {
    IngestAgent::fake([fakeIngestResponse()]);

    $this->artisan('wiki:ingest test-project article.md')->assertExitCode(0);

    $content = Storage::disk('wiki')->get('test-project/wiki/qubit.md');

    expect($content)
        ->toContain('---')
        ->toContain('title: "Кубит"')
        ->toContain('category: entity')
        ->toContain('article.md');
});

it('updates index.md with source page in ## Источники', function () {
    IngestAgent::fake([fakeIngestResponse()]);

    $this->artisan('wiki:ingest test-project article.md')->assertExitCode(0);

    $index = Storage::disk('wiki')->get('test-project/wiki/index.md');
    expect($index)
        ->toContain('[[source-article]]')
        ->toContain('[[quantum-computing]]')
        ->toContain('[[qubit]]');
});

it('appends a rich entry to log.md', function () {
    IngestAgent::fake([fakeIngestResponse()]);

    $this->artisan('wiki:ingest test-project article.md')->assertExitCode(0);

    $log = Storage::disk('wiki')->get('test-project/wiki/log.md');
    expect($log)
        ->toContain('ingest')
        ->toContain('article.md')
        ->toContain('[[source-article]]')
        ->toContain('Новые утверждения');
});

it('logs token usage to the database', function () {
    IngestAgent::fake([fakeIngestResponse()]);

    $this->artisan('wiki:ingest test-project article.md')->assertExitCode(0);

    $this->assertDatabaseHas('token_usages', [
        'userspace' => 'test-project',
        'operation' => 'ingest',
        'source_file' => 'article.md',
    ]);
});

it('uses production models when --production flag is set', function () {
    IngestAgent::fake([fakeIngestResponse()]);

    $this->artisan('wiki:ingest test-project article.md --production')->assertExitCode(0);

    $usage = TokenUsage::query()->latest('id')->first();
    expect($usage->model)->toBe(config('wiki.production_model', 'gpt-4o'));
});

it('writes novel_claims to _claims_log.md when present', function () {
    IngestAgent::fake([fakeIngestResponse([
        'novel_claims' => [
            [
                'claim' => 'Квантовое превосходство достигнуто в 2019',
                'contradicts' => 'quantum-supremacy',
                'extends' => null,
                'certainty' => 'confident',
            ],
        ],
    ])]);

    $this->artisan('wiki:ingest test-project article.md')->assertExitCode(0);

    Storage::disk('wiki')->assertExists('test-project/wiki/_claims_log.md');

    $claims = Storage::disk('wiki')->get('test-project/wiki/_claims_log.md');
    expect($claims)
        ->toContain('Квантовое превосходство')
        ->toContain('противоречит')
        ->toContain('[[quantum-supremacy]]');
});

it('displays contradiction warnings in console output', function () {
    IngestAgent::fake([fakeIngestResponse([
        'novel_claims' => [
            [
                'claim' => 'Новое утверждение о кубитах',
                'contradicts' => 'qubit',
                'extends' => null,
                'certainty' => 'hedged',
            ],
        ],
    ])]);

    $this->artisan('wiki:ingest test-project article.md')
        ->expectsOutputToContain('ПРОТИВОРЕЧИТ')
        ->assertExitCode(0);
});

it('merges content when a wiki page already exists', function () {
    // Pre-create the page so it already exists
    Storage::disk('wiki')->put('test-project/wiki/quantum-computing.md', <<<'MD'
---
title: "Квантовые вычисления"
category: concept
sources:
  - "old-source.md"
updated_at: "2026-01-01"
---

## Определение / Что это
Старое определение.

## Связанные заметки

MD);

    IngestAgent::fake([fakeIngestResponse()]);

    $this->artisan('wiki:ingest test-project article.md')->assertExitCode(0);

    // The page must still exist and contain the new source
    $content = Storage::disk('wiki')->get('test-project/wiki/quantum-computing.md');
    expect($content)->toContain('article.md');
});
