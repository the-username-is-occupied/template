<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('wiki');
});

it('creates the required directory structure and files', function () {
    $this->artisan('wiki:init test-project')
        ->expectsOutput("Проект 'test-project' успешно инициализирован.")
        ->assertExitCode(0);

    Storage::disk('wiki')->assertExists('test-project/wiki/index.md');
    Storage::disk('wiki')->assertExists('test-project/wiki/log.md');
    Storage::disk('wiki')->assertExists('test-project/schema/AGENTS.md');
});

it('creates index.md with correct heading', function () {
    $this->artisan('wiki:init test-project')->assertExitCode(0);

    $content = Storage::disk('wiki')->get('test-project/wiki/index.md');

    expect($content)->toContain('# Индекс вики');
});

it('creates log.md with initial entry', function () {
    $this->artisan('wiki:init test-project')->assertExitCode(0);

    $content = Storage::disk('wiki')->get('test-project/wiki/log.md');

    expect($content)
        ->toContain('# Журнал операций')
        ->toContain('init');
});

it('creates schema/AGENTS.md with wiki rules', function () {
    $this->artisan('wiki:init test-project')->assertExitCode(0);

    $content = Storage::disk('wiki')->get('test-project/schema/AGENTS.md');

    expect($content)
        ->toContain('AGENTS.md')
        ->toContain('raw/')
        ->toContain('wiki/');
});
