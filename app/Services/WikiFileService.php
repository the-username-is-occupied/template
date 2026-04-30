<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

class WikiFileService
{
    private function disk(): Filesystem
    {
        return Storage::disk('wiki');
    }

    /**
     * Initialize a new userspace with required directory structure and seed files.
     */
    public function initUserspace(string $userspace): void
    {
        $this->disk()->makeDirectory("{$userspace}/raw");
        $this->disk()->makeDirectory("{$userspace}/wiki");
        $this->disk()->makeDirectory("{$userspace}/schema");

        $today = Carbon::now()->toDateString();

        $this->disk()->put("{$userspace}/wiki/index.md", "# Индекс вики\n");

        $this->disk()->put(
            "{$userspace}/wiki/log.md",
            "# Журнал операций\n\n## [{$today}] init\n- Создана структура проекта\n"
        );

        $this->disk()->put("{$userspace}/schema/AGENTS.md", $this->buildAgentsMdContent());
    }

    /**
     * Read a raw source file from the userspace.
     */
    public function getRawContent(string $userspace, string $filename): string
    {
        return $this->disk()->get("{$userspace}/raw/{$filename}") ?? '';
    }

    /**
     * Determine if a raw file exists in the userspace.
     */
    public function rawFileExists(string $userspace, string $filename): bool
    {
        return $this->disk()->exists("{$userspace}/raw/{$filename}");
    }

    /**
     * Read the raw body of an existing wiki page (without frontmatter).
     * Returns null if the page does not exist.
     */
    public function getWikiPageContent(string $userspace, string $slug): ?string
    {
        $path = "{$userspace}/wiki/{$slug}.md";
        if (! $this->disk()->exists($path)) {
            return null;
        }

        $content = $this->disk()->get($path) ?? '';

        // Strip YAML frontmatter
        if (preg_match('/^---\s*\n.*?\n---\s*\n/s', $content, $matches)) {
            return ltrim(substr($content, strlen($matches[0])));
        }

        return $content;
    }

    /**
     * Write or overwrite a wiki page with YAML frontmatter.
     *
     * $linkedTo items are either plain strings (legacy) or arrays with keys: slug, relationship.
     *
     * @param  array{title: string, slug: string, category: string, content: string, linked_to: array<int, string|array{slug: string, relationship: string}>}  $page
     * @param  string[]  $sources
     */
    public function writeWikiPage(string $userspace, array $page, array $sources, bool $isUpdate = false): void
    {
        $path = "{$userspace}/wiki/{$page['slug']}.md";
        $today = Carbon::now()->toDateString();

        $existingSources = $isUpdate ? $this->extractSourcesFromFrontmatter($path) : [];
        $mergedSources = array_values(array_unique(array_merge($existingSources, $sources)));
        $sourcesYaml = implode("\n", array_map(fn ($s) => "  - \"{$s}\"", $mergedSources));

        $linkedSection = $this->buildLinkedSection($page['linked_to'] ?? []);

        $body = rtrim($page['content']);

        $fileContent = <<<MD
---
title: "{$page['title']}"
category: {$page['category']}
sources:
{$sourcesYaml}
updated_at: "{$today}"
---

{$body}

{$linkedSection}
MD;

        $this->disk()->put($path, $fileContent);
    }

    /**
     * Determine if a wiki page already exists (used to decide create vs. merge).
     */
    public function wikiPageExists(string $userspace, string $slug): bool
    {
        return $this->disk()->exists("{$userspace}/wiki/{$slug}.md");
    }

    /**
     * Update the wiki index with new pages grouped by category.
     *
     * @param  array<int, array{title: string, slug: string, category: string, content: string}>  $pages
     * @param  array{title: string, slug: string, content: string}  $sourcePage
     */
    public function updateIndex(
        string $userspace,
        string $rawFilename,
        string $overallSummary,
        array $pages,
        array $sourcePage
    ): void {
        $indexPath = "{$userspace}/wiki/index.md";
        $existing = $this->disk()->exists($indexPath)
            ? ($this->disk()->get($indexPath) ?? '')
            : "# Индекс вики\n";

        $existing = $this->ensureIndexSection($existing, '## Источники');
        $existing = $this->ensureIndexSection($existing, '## Сущности');
        $existing = $this->ensureIndexSection($existing, '## Концепции');

        // Add the source overview page to ## Источники
        $existing = $this->upsertIndexEntry(
            $existing,
            '## Источники',
            $sourcePage['slug'],
            $overallSummary
        );

        foreach ($pages as $page) {
            $sectionHeader = $page['category'] === 'entity' ? '## Сущности' : '## Концепции';

            $firstLine = $this->extractFirstSentence($page['content']);
            $existing = $this->upsertIndexEntry($existing, $sectionHeader, $page['slug'], $firstLine);
        }

        $this->disk()->put($indexPath, $existing);
    }

    /**
     * Append an ingest operation entry to log.md.
     *
     * @param  string[]  $createdSlugs
     * @param  string[]  $updatedSlugs
     */
    public function appendIngestLog(
        string $userspace,
        string $rawFilename,
        string $sourcePageSlug,
        array $createdSlugs,
        array $updatedSlugs,
        string $provider,
        string $model,
        int $totalTokens,
        float $totalCost,
        int $totalClaims,
        int $contradictingClaims,
        int $extendingClaims
    ): void {
        $today = Carbon::now()->toDateString();
        $logPath = "{$userspace}/wiki/log.md";

        $existing = $this->disk()->exists($logPath)
            ? ($this->disk()->get($logPath) ?? "# Журнал операций\n")
            : "# Журнал операций\n";

        $created = implode(', ', array_map(fn ($s) => "[[{$s}]]", $createdSlugs));
        $updated = implode(', ', array_map(fn ($s) => "[[{$s}]]", $updatedSlugs));

        $entry = "\n## [{$today}] ingest | {$rawFilename}\n";
        $entry .= "- Источник: [[{$sourcePageSlug}]]\n";
        $entry .= "- Созданы страницы: {$created}\n";
        $entry .= "- Обновлены страницы: {$updated}\n";
        $entry .= "- Новые утверждения: {$totalClaims} ({$contradictingClaims} противоречат существующим, {$extendingClaims} расширяют)\n";
        $entry .= "- Провайдер: {$provider}\n";
        $entry .= "- Модель: {$model}\n";
        $entry .= "- Токенов: {$totalTokens}, Стоимость: \${$totalCost}\n";

        $this->disk()->put($logPath, $existing.$entry);
    }

    /**
     * Append a lint operation entry to log.md.
     */
    public function appendLintLog(
        string $userspace,
        int $checkedPages,
        int $problemCount,
        int $brokenLinks,
        int $orphans
    ): void {
        $today = Carbon::now()->toDateString();
        $logPath = "{$userspace}/wiki/log.md";

        $existing = $this->disk()->exists($logPath)
            ? ($this->disk()->get($logPath) ?? "# Журнал операций\n")
            : "# Журнал операций\n";

        $entry = "\n## [{$today}] lint\n";
        $entry .= "- Проверено страниц: {$checkedPages}\n";
        $entry .= "- Найдено проблем: {$problemCount}\n";
        $entry .= "- Битых ссылок: {$brokenLinks}\n";
        $entry .= "- Сирот: {$orphans}\n";

        $this->disk()->put($logPath, $existing.$entry);
    }

    /**
     * Append a query answer as a synthesis wiki page.
     */
    public function saveQueryAnswer(string $userspace, string $slug, string $title, string $content): void
    {
        $today = Carbon::now()->toDateString();
        $path = "{$userspace}/wiki/{$slug}.md";

        $fileContent = <<<MD
---
title: "{$title}"
category: synthesis
sources: []
updated_at: "{$today}"
---

{$content}

## Связанные заметки

MD;

        $this->disk()->put($path, $fileContent);

        $this->upsertSynthesisInIndex($userspace, $slug, $title);

        $logPath = "{$userspace}/wiki/log.md";
        $existing = $this->disk()->exists($logPath)
            ? ($this->disk()->get($logPath) ?? "# Журнал операций\n")
            : "# Журнал операций\n";

        $entry = "\n## [{$today}] query | Синтез\n";
        $entry .= "- Сохранена страница: [[{$slug}]]\n";

        $this->disk()->put($logPath, $existing.$entry);
    }

    /**
     * Append novel claims to _claims_log.md.
     *
     * @param  array<int, array{claim: string, contradicts: string|null, extends: string|null, certainty: string}>  $claims
     */
    public function appendClaimsLog(string $userspace, string $sourceSlug, array $claims): void
    {
        if (empty($claims)) {
            return;
        }

        $today = Carbon::now()->toDateString();
        $claimsPath = "{$userspace}/wiki/_claims_log.md";

        $existing = $this->disk()->exists($claimsPath)
            ? ($this->disk()->get($claimsPath) ?? "# Журнал утверждений\n")
            : "# Журнал утверждений\n";

        $rows = '';
        foreach ($claims as $c) {
            $type = $c['contradicts'] ? 'противоречит' : ($c['extends'] ? 'расширяет' : 'новое');
            $affects = $c['contradicts'] ?? $c['extends'] ?? '—';
            $affectsStr = $affects !== '—' ? "[[{$affects}]]" : '—';
            $rows .= "| {$c['claim']} | {$type} | {$affectsStr} | {$c['certainty']} |\n";
        }

        $entry = "\n## [{$today}] Из источника [[{$sourceSlug}]]\n\n";
        $entry .= "| Утверждение | Тип | Затрагивает | Уверенность |\n";
        $entry .= "|---|---|---|---|\n";
        $entry .= $rows;

        $this->disk()->put($claimsPath, $existing.$entry);
    }

    /**
     * Lint all wiki pages in the userspace.
     *
     * @return array{
     *   checked_pages: int,
     *   broken_links: array<string, string[]>,
     *   no_frontmatter: string[],
     *   orphans: string[],
     *   dead_index_links: string[],
     * }
     */
    public function lintWiki(string $userspace): array
    {
        $wikiPath = "{$userspace}/wiki";
        $allFiles = $this->disk()->files($wikiPath);

        $skippedFiles = ['index.md', 'log.md', '_claims_log.md'];

        $pageFiles = array_filter($allFiles, function ($file) use ($skippedFiles) {
            return str_ends_with($file, '.md') && ! in_array(basename($file), $skippedFiles, true);
        });

        $existingSlugs = array_map(fn ($f) => pathinfo(basename($f), PATHINFO_FILENAME), $pageFiles);

        $brokenLinks = [];
        $noFrontmatter = [];

        foreach ($pageFiles as $file) {
            $content = $this->disk()->get($file) ?? '';

            if (! $this->hasFrontmatter($content)) {
                $noFrontmatter[] = basename($file);
            }

            preg_match_all('/\[\[([^\]]+)\]\]/', $content, $matches);
            $linkedSlugs = $matches[1] ?? [];

            foreach ($linkedSlugs as $linkedSlug) {
                if (! in_array($linkedSlug, $existingSlugs, true)) {
                    $brokenLinks[basename($file)][] = $linkedSlug;
                }
            }
        }

        $indexContent = '';
        $deadIndexLinks = [];

        if ($this->disk()->exists("{$wikiPath}/index.md")) {
            $indexContent = $this->disk()->get("{$wikiPath}/index.md") ?? '';
            preg_match_all('/\[\[([^\]]+)\]\]/', $indexContent, $indexMatches);
            $indexLinkedSlugs = $indexMatches[1] ?? [];

            foreach ($indexLinkedSlugs as $linkedSlug) {
                if (! in_array($linkedSlug, $existingSlugs, true)) {
                    $deadIndexLinks[] = $linkedSlug;
                }
            }
        }

        $orphans = [];
        foreach ($existingSlugs as $slug) {
            if (! str_contains($indexContent, "[[{$slug}]]")) {
                $orphans[] = "{$slug}.md";
            }
        }

        return [
            'checked_pages' => count($pageFiles),
            'broken_links' => $brokenLinks,
            'no_frontmatter' => $noFrontmatter,
            'orphans' => $orphans,
            'dead_index_links' => $deadIndexLinks,
        ];
    }

    private function hasFrontmatter(string $content): bool
    {
        return str_starts_with(trim($content), '---');
    }

    /**
     * @return string[]
     */
    private function extractSourcesFromFrontmatter(string $path): array
    {
        $content = $this->disk()->get($path) ?? '';
        if (! preg_match('/^---\s*\n(.*?)\n---/s', $content, $matches)) {
            return [];
        }

        preg_match_all('/sources:\s*\n((?:\s+-[^\n]*\n?)+)/', $matches[1], $srcMatches);
        if (empty($srcMatches[1][0])) {
            return [];
        }

        preg_match_all('/\s+-\s+"?([^"\n]+)"?\s*/', $srcMatches[1][0], $items);

        return $items[1] ?? [];
    }

    private function ensureIndexSection(string $content, string $header): string
    {
        if (! str_contains($content, $header)) {
            $content = rtrim($content)."\n\n{$header}\n";
        }

        return $content;
    }

    private function upsertIndexEntry(string $content, string $sectionHeader, string $slug, string $description): string
    {
        $entry = "- [[{$slug}]] — {$description}";
        $pattern = '/- \[\['.preg_quote($slug, '/').']].*$/m';

        if (preg_match($pattern, $content)) {
            return preg_replace($pattern, $entry, $content);
        }

        return preg_replace(
            '/('.preg_quote($sectionHeader, '/').')(\n|$)/m',
            "$1\n{$entry}\n",
            $content
        );
    }

    private function upsertSynthesisInIndex(string $userspace, string $slug, string $title): void
    {
        $indexPath = "{$userspace}/wiki/index.md";
        $existing = $this->disk()->exists($indexPath)
            ? ($this->disk()->get($indexPath) ?? "# Индекс вики\n")
            : "# Индекс вики\n";

        $existing = $this->ensureIndexSection($existing, '## Концепции');
        $existing = $this->upsertIndexEntry($existing, '## Концепции', $slug, $title);

        $this->disk()->put($indexPath, $existing);
    }

    /**
     * Build the "## Связанные заметки" section from linked_to entries.
     *
     * Each entry may be a plain string slug (legacy) or array{slug, relationship}.
     *
     * @param  array<int, string|array{slug: string, relationship: string}>  $linkedTo
     */
    private function buildLinkedSection(array $linkedTo): string
    {
        if (empty($linkedTo)) {
            return "## Связанные заметки\n";
        }

        $lines = array_map(function ($item) {
            if (is_array($item)) {
                return "- [[{$item['slug']}]] — {$item['relationship']}";
            }

            return "- [[{$item}]]";
        }, $linkedTo);

        return "## Связанные заметки\n".implode("\n", $lines)."\n";
    }

    private function extractFirstSentence(string $content): string
    {
        // Strip markdown headings, bold/italic markers, then get first non-empty line
        $clean = preg_replace('/^#+\s+.*/m', '', $content);
        $clean = preg_replace('/[*_`]+/', '', $clean ?? '');
        $lines = array_filter(array_map('trim', explode("\n", $clean ?? '')));
        $first = reset($lines) ?: '';

        return mb_strlen($first) > 100 ? mb_substr($first, 0, 97).'...' : $first;
    }

    private function buildAgentsMdContent(): string
    {
        return <<<'MD'
# AGENTS.md — Схема вики

## Структура директорий
- `raw/` — исходные файлы (только чтение)
- `wiki/` — сгенерированные страницы (LLM пишет)
- `schema/` — этот файл с правилами

## Правила именования
- Слаг страницы: транслитерация или перевод на английский, строчные буквы, дефисы вместо пробелов
- Имя файла: `{slug}.md`

## Frontmatter страниц вики
```yaml
---
title: "Человекочитаемое название"
category: entity|concept|summary|synthesis
sources:
  - "имя-raw-файла"
updated_at: "YYYY-MM-DD"
---
```

## Структура тела страницы
```
# Title

## Определение / Что это
## Ключевая информация
## Контекст и значение
## Связи
## Цитаты и утверждения
## Связанные заметки
- [[slug]] — описание отношения
```

## Правила поддержки
- При каждом ingest: создавать/обновлять страницы, актуализировать index.md и log.md
- При каждом вопросе: искать ответ только в вики
- Lint: регулярно проверять целостность ссылок
- Связи: всегда указывать двусторонние ссылки между страницами
- Новые утверждения: сохранять в _claims_log.md с уровнем уверенности
MD;
    }
}
