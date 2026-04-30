<?php

declare(strict_types=1);

use App\Services\WikiConfig;
use App\Services\WikiFileService;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('wiki');
});

it('initializes userspace with correct structure', function () {
    $service = app(WikiFileService::class);
    $service->initUserspace('my-project');

    Storage::disk('wiki')->assertExists('my-project/wiki/index.md');
    Storage::disk('wiki')->assertExists('my-project/wiki/log.md');
    Storage::disk('wiki')->assertExists('my-project/schema/AGENTS.md');
});

it('writes a new wiki page with frontmatter and returns false', function () {
    $service = app(WikiFileService::class);
    Storage::disk('wiki')->makeDirectory('proj/wiki');

    $page = [
        'title' => 'Test Entity',
        'slug' => 'test-entity',
        'category' => 'entity',
        'content' => 'Description here.',
        'linked_to' => ['other-page'],
    ];

    $wasUpdate = $service->writeWikiPage('proj', $page, ['source.md']);

    expect($wasUpdate)->toBeFalse();

    $content = Storage::disk('wiki')->get('proj/wiki/test-entity.md');
    expect($content)
        ->toContain('title: "Test Entity"')
        ->toContain('category: entity')
        ->toContain('sources:')
        ->toContain('source.md')
        ->toContain('Description here.')
        ->toContain('## Связанные заметки')
        ->toContain('[[other-page]]');
});

it('returns true when updating an existing wiki page', function () {
    $service = app(WikiFileService::class);
    Storage::disk('wiki')->makeDirectory('proj/wiki');

    $page = [
        'title' => 'Existing',
        'slug' => 'existing',
        'category' => 'concept',
        'content' => 'Initial content.',
        'linked_to' => [],
    ];

    $service->writeWikiPage('proj', $page, ['first.md']);
    $wasUpdate = $service->writeWikiPage('proj', $page, ['second.md']);

    expect($wasUpdate)->toBeTrue();

    $content = Storage::disk('wiki')->get('proj/wiki/existing.md');
    expect($content)
        ->toContain('first.md')
        ->toContain('second.md');
});

it('merges sources from existing page on update', function () {
    $service = app(WikiFileService::class);
    Storage::disk('wiki')->makeDirectory('proj/wiki');

    $page = ['title' => 'Multi-source', 'slug' => 'multi-src', 'category' => 'concept', 'content' => 'C', 'linked_to' => []];

    $service->writeWikiPage('proj', $page, ['a.md']);
    $service->writeWikiPage('proj', $page, ['b.md']);

    $content = Storage::disk('wiki')->get('proj/wiki/multi-src.md');
    expect($content)->toContain('a.md')->toContain('b.md');
});

it('lints wiki and reports orphans', function () {
    $service = app(WikiFileService::class);
    Storage::disk('wiki')->makeDirectory('proj/wiki');
    Storage::disk('wiki')->put('proj/wiki/index.md', "# Индекс вики\n");
    Storage::disk('wiki')->put('proj/wiki/orphan.md', "---\ntitle: \"X\"\ncategory: entity\nsources: []\nupdated_at: \"2026-01-01\"\n---\nContent.\n\n## Связанные заметки\n");

    $result = $service->lintWiki('proj');

    expect($result['orphans'])->toContain('orphan.md');
});

it('lints wiki and reports pages without frontmatter', function () {
    $service = app(WikiFileService::class);
    Storage::disk('wiki')->makeDirectory('proj/wiki');
    Storage::disk('wiki')->put('proj/wiki/index.md', "# Индекс вики\n");
    Storage::disk('wiki')->put('proj/wiki/no-fm.md', "Just content without frontmatter.\n");

    $result = $service->lintWiki('proj');

    expect($result['no_frontmatter'])->toContain('no-fm.md');
});

it('lints wiki and reports broken links', function () {
    $service = app(WikiFileService::class);
    Storage::disk('wiki')->makeDirectory('proj/wiki');
    Storage::disk('wiki')->put('proj/wiki/index.md', "# Индекс вики\n\n## Сущности\n- [[page-a]] — page a\n");
    Storage::disk('wiki')->put('proj/wiki/page-a.md', "---\ntitle: \"A\"\ncategory: entity\nsources: []\nupdated_at: \"2026-01-01\"\n---\n\n[[nonexistent]]\n\n## Связанные заметки\n");

    $result = $service->lintWiki('proj');

    expect($result['broken_links'])->toHaveKey('page-a.md');
    expect($result['broken_links']['page-a.md'])->toContain('nonexistent');
});

it('calculates cost correctly via WikiConfig', function () {
    $config = app(WikiConfig::class);
    $costs = $config->calculateCost('gpt-4o-mini', 1_000_000, 1_000_000);

    expect($costs['input'])->toBe(0.15)
        ->and($costs['output'])->toBe(0.60)
        ->and($costs['total'])->toBe(0.75);
});
