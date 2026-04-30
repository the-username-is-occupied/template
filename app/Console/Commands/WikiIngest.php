<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Ai\Agents\IngestAgent;
use App\Ai\Agents\MergeWikiPageAgent;
use App\Services\TokenUsageLogger;
use App\Services\WikiConfig;
use App\Services\WikiFileService;
use Illuminate\Console\Command;
use Laravel\Ai\Exceptions\FailoverableException;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\StructuredAgentResponse;

class WikiIngest extends Command
{
    protected $signature = 'wiki:ingest
        {userspace : The userspace/project slug}
        {filename : Name of the file already placed in {userspace}/raw/}
        {--production : Use production-grade models}
        {--provider= : Manually override the AI provider}';

    protected $description = 'Read a raw file and extract structured wiki pages from it';

    public function handle(
        WikiConfig $wikiConfig,
        WikiFileService $fileService,
        TokenUsageLogger $usageLogger
    ): int {
        $userspace = $this->argument('userspace');
        $filename = $this->argument('filename');

        if (! $fileService->rawFileExists($userspace, $filename)) {
            $this->error("Файл не найден: {$userspace}/raw/{$filename}");

            return self::FAILURE;
        }

        $providerConfig = $this->resolveProvider($wikiConfig);
        $provider = $providerConfig['provider'];
        $model = $providerConfig['model'];

        $rawContent = $fileService->getRawContent($userspace, $filename);

        $this->line('Провайдер: '.ucfirst($provider));
        $this->line("Модель: {$model}");

        $agent = new IngestAgent;

        /** @var StructuredAgentResponse $response */
        $response = $this->promptWithFallback(
            fn () => $agent->prompt(
                "Source file: {$filename}\n\nExtract knowledge from the following document:\n\n{$rawContent}",
                provider: $provider,
                model: $model
            ),
            function () use ($agent, $wikiConfig, $filename, $rawContent, &$provider, &$model) {
                $fallback = $wikiConfig->getFallbackProvider();
                $this->warn("Основной провайдер недоступен. Переключаюсь на fallback: {$fallback['provider']}/{$fallback['model']}");
                $provider = $fallback['provider'];
                $model = $fallback['model'];

                return $agent->prompt(
                    "Source file: {$filename}\n\nExtract knowledge from the following document:\n\n{$rawContent}",
                    provider: $provider,
                    model: $model
                );
            }
        );

        $inputTokens = $response->usage->promptTokens;
        $outputTokens = $response->usage->completionTokens;
        $costs = $wikiConfig->calculateCost($model, $inputTokens, $outputTokens);
        $totalCost = $costs['total'];

        $this->line(sprintf(
            'Токенов: %s in / %s out',
            number_format($inputTokens),
            number_format($outputTokens)
        ));
        $this->line(sprintf('Стоимость: $%.4f', $totalCost));
        $this->newLine();

        $structured = $response->toArray();
        $overallSummary = $structured['overall_summary'] ?? '';
        $sourcePage = $structured['source_page'] ?? [];
        $pages = $structured['pages'] ?? [];
        $novelClaims = $structured['novel_claims'] ?? [];

        // --- Write source overview page ---
        $this->line('📄 Источник:');
        $sourcePageSlug = $sourcePage['slug'] ?? 'source-'.str_replace(['.', ' '], '-', $filename);
        $sourcePageData = [
            'title' => $sourcePage['title'] ?? "Обзор: {$filename}",
            'slug' => $sourcePageSlug,
            'category' => 'concept',
            'content' => $sourcePage['content'] ?? '',
            'linked_to' => [],
        ];
        $sourceIsUpdate = $fileService->wikiPageExists($userspace, $sourcePageSlug);
        if ($sourceIsUpdate) {
            $this->mergeExistingPage($userspace, $sourcePageData, [$filename], $fileService, $wikiConfig, $provider, $model);
        } else {
            $fileService->writeWikiPage($userspace, $sourcePageData, [$filename]);
        }
        $this->line("  + {$sourcePageSlug} (обзор источника)");

        // --- Write entity/concept pages ---
        $createdSlugs = [];
        $updatedSlugs = [];

        foreach ($pages as $page) {
            $isUpdate = $fileService->wikiPageExists($userspace, $page['slug']);

            if ($isUpdate) {
                $this->mergeExistingPage($userspace, $page, [$filename], $fileService, $wikiConfig, $provider, $model);
                $updatedSlugs[] = $page['slug'];
            } else {
                $fileService->writeWikiPage($userspace, $page, [$filename]);
                $createdSlugs[] = $page['slug'];
            }
        }

        if (! empty($createdSlugs)) {
            $this->newLine();
            $this->line('🆕 Созданы страницы:');
            foreach ($createdSlugs as $slug) {
                $category = collect($pages)->firstWhere('slug', $slug)['category'] ?? '';
                $this->line("  + {$slug} ({$category})");
            }
        }

        if (! empty($updatedSlugs)) {
            $this->newLine();
            $this->line('🔄 Обновлены страницы:');
            foreach ($updatedSlugs as $slug) {
                $category = collect($pages)->firstWhere('slug', $slug)['category'] ?? '';
                $this->line("  ~ {$slug} ({$category})");
            }
        }

        // --- Novel claims ---
        $contradictingClaims = array_filter($novelClaims, fn ($c) => ! empty($c['contradicts']));
        $extendingClaims = array_filter($novelClaims, fn ($c) => ! empty($c['extends']));

        if (! empty($novelClaims)) {
            $this->newLine();
            $this->line('⚠ Новые утверждения:');
            foreach ($novelClaims as $claim) {
                if (! empty($claim['contradicts'])) {
                    $this->line("  ⚡ ПРОТИВОРЕЧИТ: \"{$claim['claim']}\" → [[{$claim['contradicts']}]]");
                } elseif (! empty($claim['extends'])) {
                    $this->line("  🔧 РАСШИРЯЕТ: \"{$claim['claim']}\" → [[{$claim['extends']}]]");
                } else {
                    $this->line("  ℹ НОВОЕ: \"{$claim['claim']}\"");
                }
            }
        }

        // --- Update index ---
        $fileService->updateIndex($userspace, $filename, $overallSummary, $pages, $sourcePageData);
        $this->newLine();
        $this->line('index.md обновлён');

        // --- Update log ---
        $fileService->appendIngestLog(
            $userspace,
            $filename,
            $sourcePageSlug,
            $createdSlugs,
            $updatedSlugs,
            $provider,
            $model,
            $inputTokens + $outputTokens,
            $totalCost,
            count($novelClaims),
            count($contradictingClaims),
            count($extendingClaims)
        );
        $this->line('log.md обновлён');

        // --- Claims log ---
        if (! empty($novelClaims)) {
            $fileService->appendClaimsLog($userspace, $sourcePageSlug, $novelClaims);
            $this->line('_claims_log.md обновлён');
        }

        // --- Token usage ---
        $usageLogger->log($response, [
            'userspace' => $userspace,
            'operation' => 'ingest',
            'provider' => $provider,
            'model' => $model,
            'source_file' => $filename,
        ]);

        return self::SUCCESS;
    }

    /**
     * Merge an existing wiki page with new content via the MergeWikiPageAgent.
     *
     * @param  array{title: string, slug: string, category: string, content: string, linked_to: array<int, mixed>}  $page
     * @param  string[]  $newSources
     */
    private function mergeExistingPage(
        string $userspace,
        array $page,
        array $newSources,
        WikiFileService $fileService,
        WikiConfig $wikiConfig,
        string $provider,
        string $model
    ): void {
        $existingBody = $fileService->getWikiPageContent($userspace, $page['slug']) ?? '';

        $mergeAgent = new MergeWikiPageAgent;

        $mergePrompt = "EXISTING VERSION:\n\n{$existingBody}\n\n---\n\nNEW VERSION:\n\n{$page['content']}";

        try {
            $mergeResponse = $mergeAgent->prompt($mergePrompt, provider: $provider, model: $model);
            $page['content'] = $mergeResponse->text;
        } catch (\Throwable) {
            // If merge fails, keep the new content as-is
        }

        $fileService->writeWikiPage($userspace, $page, $newSources, isUpdate: true);
    }

    /**
     * Run the primary callback; on FailoverableException run the fallback.
     */
    private function promptWithFallback(callable $primary, callable $fallback): AgentResponse
    {
        try {
            return $primary();
        } catch (FailoverableException) {
            return $fallback();
        }
    }

    /**
     * @return array{provider: string, model: string}
     */
    private function resolveProvider(WikiConfig $wikiConfig): array
    {
        if ($this->option('provider')) {
            $isProduction = $this->option('production');
            $base = $isProduction ? $wikiConfig->getProductionProvider() : $wikiConfig->getTestingProvider();

            return ['provider' => $this->option('provider'), 'model' => $base['model']];
        }

        return $this->option('production')
            ? $wikiConfig->getProductionProvider()
            : $wikiConfig->getTestingProvider();
    }
}
