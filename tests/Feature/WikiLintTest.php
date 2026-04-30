<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('wiki');
    Storage::disk('wiki')->makeDirectory('test-project/wiki');
    Storage::disk('wiki')->put('test-project/wiki/index.md', "# Индекс вики\n\n## Сущности\n- [[good-page]] — хорошая страница\n");
    Storage::disk('wiki')->put('test-project/wiki/log.md', "# Журнал операций\n");
});

it('reports zero problems for a clean wiki', function () {
    Storage::disk('wiki')->put('test-project/wiki/good-page.md', <<<'MD'
---
title: "Хорошая страница"
category: entity
sources:
  - "source.md"
updated_at: "2026-01-01"
---

Содержимое страницы.

## Связанные заметки

MD);

    $this->artisan('wiki:lint test-project')
        ->expectsOutputToContain('Проверено страниц: 1')
        ->expectsOutputToContain('Найдено проблем: 0')
        ->assertExitCode(0);
});

it('detects pages without frontmatter', function () {
    Storage::disk('wiki')->put('test-project/wiki/no-frontmatter.md', "Just some content without frontmatter.\n");
    Storage::disk('wiki')->put('test-project/wiki/index.md', "# Индекс вики\n\n## Сущности\n- [[no-frontmatter]] — page\n");

    $this->artisan('wiki:lint test-project')
        ->expectsOutputToContain('Страницы без frontmatter')
        ->expectsOutputToContain('no-frontmatter.md')
        ->assertExitCode(0);
});

it('detects broken wiki links', function () {
    Storage::disk('wiki')->put('test-project/wiki/page-with-broken-link.md', <<<'MD'
---
title: "Страница с битой ссылкой"
category: entity
sources:
  - "source.md"
updated_at: "2026-01-01"
---

Ссылка на [[nonexistent-page]].

## Связанные заметки

MD);
    Storage::disk('wiki')->put('test-project/wiki/index.md', "# Индекс вики\n\n## Сущности\n- [[page-with-broken-link]] — page\n");

    $this->artisan('wiki:lint test-project')
        ->expectsOutputToContain('Битые ссылки')
        ->expectsOutputToContain('nonexistent-page')
        ->assertExitCode(0);
});

it('detects orphaned pages not mentioned in index', function () {
    Storage::disk('wiki')->put('test-project/wiki/orphan-page.md', <<<'MD'
---
title: "Сирота"
category: concept
sources: []
updated_at: "2026-01-01"
---

Содержимое.

## Связанные заметки

MD);

    $this->artisan('wiki:lint test-project')
        ->expectsOutputToContain('Сироты')
        ->expectsOutputToContain('orphan-page.md')
        ->assertExitCode(0);
});

it('detects dead links in index pointing to nonexistent files', function () {
    Storage::disk('wiki')->put('test-project/wiki/index.md', "# Индекс вики\n\n## Сущности\n- [[ghost-page]] — несуществующая\n");

    $this->artisan('wiki:lint test-project')
        ->expectsOutputToContain('Мёртвые ссылки')
        ->expectsOutputToContain('ghost-page')
        ->assertExitCode(0);
});

it('appends a lint entry to log.md', function () {
    $this->artisan('wiki:lint test-project')->assertExitCode(0);

    $log = Storage::disk('wiki')->get('test-project/wiki/log.md');
    expect($log)->toContain('lint')->toContain('Проверено страниц');
});
