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

it('writes a new wiki page with frontmatter and linked section', function () {
    $service = app(WikiFileService::class);
    Storage::disk('wiki')->makeDirectory('proj/wiki');

    $page = [
        'title' => 'Test Entity',
        'slug' => 'test-entity',
        'category' => 'entity',
        'content' => "## Определение / Что это\nDescription here.\n\n## Ключевая информация\nFacts.\n\n## Контекст и значение\nContext.\n\n## Связи\nLinks.\n\n## Цитаты и утверждения\nNone.",
        'linked_to' => [
            ['slug' => 'other-page', 'relationship' => 'зависит от'],
        ],
    ];

    $service->writeWikiPage('proj', $page, ['source.md']);

    $content = Storage::disk('wiki')->get('proj/wiki/test-entity.md');
    expect($content)
        ->toContain('title: "Test Entity"')
        ->toContain('category: entity')
        ->toContain('source.md')
        ->toContain('## Определение')
        ->toContain('## Связанные заметки')
        ->toContain('[[other-page]] — зависит от');
});

it('merges sources from existing page on update', function () {
    $service = app(WikiFileService::class);
    Storage::disk('wiki')->makeDirectory('proj/wiki');

    $page = [
        'title' => 'Multi-source',
        'slug' => 'multi-src',
        'category' => 'concept',
        'content' => "## Определение / Что это\nC.\n\n## Связанные заметки\n",
        'linked_to' => [],
    ];

    $service->writeWikiPage('proj', $page, ['a.md']);
    $service->writeWikiPage('proj', $page, ['b.md'], isUpdate: true);

    $content = Storage::disk('wiki')->get('proj/wiki/multi-src.md');
    expect($content)->toContain('a.md')->toContain('b.md');
});

it('returns existing wiki page body without frontmatter', function () {
    $service = app(WikiFileService::class);
    Storage::disk('wiki')->makeDirectory('proj/wiki');
    Storage::disk('wiki')->put('proj/wiki/my-page.md', "---\ntitle: \"My Page\"\ncategory: concept\nsources: []\nupdated_at: \"2026-01-01\"\n---\n\nBody content here.\n");

    $body = $service->getWikiPageContent('proj', 'my-page');
    expect($body)->toContain('Body content here.')->not->toContain('---');
});

it('returns null when wiki page does not exist', function () {
    $service = app(WikiFileService::class);
    expect($service->getWikiPageContent('proj', 'nonexistent'))->toBeNull();
});

it('detects whether a wiki page exists', function () {
    $service = app(WikiFileService::class);
    Storage::disk('wiki')->makeDirectory('proj/wiki');
    Storage::disk('wiki')->put('proj/wiki/existing.md', 'content');

    expect($service->wikiPageExists('proj', 'existing'))->toBeTrue();
    expect($service->wikiPageExists('proj', 'nonexistent'))->toBeFalse();
});

it('appends novel claims to _claims_log.md', function () {
    $service = app(WikiFileService::class);
    Storage::disk('wiki')->makeDirectory('proj/wiki');

    $claims = [
        ['claim' => 'Claim A', 'contradicts' => 'page-x', 'extends' => null, 'certainty' => 'confident'],
        ['claim' => 'Claim B', 'contradicts' => null, 'extends' => 'page-y', 'certainty' => 'hedged'],
    ];

    $service->appendClaimsLog('proj', 'source-overview', $claims);

    Storage::disk('wiki')->assertExists('proj/wiki/_claims_log.md');
    $content = Storage::disk('wiki')->get('proj/wiki/_claims_log.md');

    expect($content)
        ->toContain('Claim A')
        ->toContain('противоречит')
        ->toContain('[[page-x]]')
        ->toContain('Claim B')
        ->toContain('расширяет')
        ->toContain('[[page-y]]');
});

it('lints wiki and excludes _claims_log.md from page count', function () {
    $service = app(WikiFileService::class);
    Storage::disk('wiki')->makeDirectory('proj/wiki');
    Storage::disk('wiki')->put('proj/wiki/index.md', "# Индекс вики\n");
    Storage::disk('wiki')->put('proj/wiki/_claims_log.md', "# Журнал утверждений\n");

    $result = $service->lintWiki('proj');
    expect($result['checked_pages'])->toBe(0);
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
