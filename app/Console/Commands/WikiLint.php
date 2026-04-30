<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\WikiFileService;
use Illuminate\Console\Command;

class WikiLint extends Command
{
    protected $signature = 'wiki:lint {userspace : The userspace/project slug}';

    protected $description = 'Lint all wiki pages for broken links, missing frontmatter, and orphaned pages';

    public function handle(WikiFileService $fileService): int
    {
        $userspace = $this->argument('userspace');

        $result = $fileService->lintWiki($userspace);

        $brokenLinks = $result['broken_links'];
        $noFrontmatter = $result['no_frontmatter'];
        $orphans = $result['orphans'];
        $deadIndexLinks = $result['dead_index_links'];

        $problemCount = count($brokenLinks) + count($noFrontmatter) + count($orphans) + count($deadIndexLinks);

        $this->line("Проверено страниц: {$result['checked_pages']}");
        $this->line("Найдено проблем: {$problemCount}");

        if (! empty($brokenLinks)) {
            $this->newLine();
            $this->line('⚠ Битые ссылки:');
            foreach ($brokenLinks as $file => $slugs) {
                foreach ($slugs as $slug) {
                    $this->line("  - {$file} → [[{$slug}]] (файл не найден)");
                }
            }
        }

        if (! empty($noFrontmatter)) {
            $this->newLine();
            $this->line('⚠ Страницы без frontmatter:');
            foreach ($noFrontmatter as $file) {
                $this->line("  - {$file}");
            }
        }

        if (! empty($orphans)) {
            $this->newLine();
            $this->line('⚠ Сироты (нет в index.md):');
            foreach ($orphans as $file) {
                $this->line("  - {$file}");
            }
        }

        if (! empty($deadIndexLinks)) {
            $this->newLine();
            $this->line('⚠ Мёртвые ссылки в index.md:');
            foreach ($deadIndexLinks as $slug) {
                $this->line("  - [[{$slug}]] (файл не найден)");
            }
        }

        $brokenLinkCount = (int) array_sum(array_map('count', $brokenLinks));

        $fileService->appendLintLog(
            $userspace,
            $result['checked_pages'],
            $problemCount,
            $brokenLinkCount,
            count($orphans)
        );

        $this->newLine();
        $this->line('log.md обновлён');

        return self::SUCCESS;
    }
}
