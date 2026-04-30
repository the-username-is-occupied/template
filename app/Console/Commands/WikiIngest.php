<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Ai\Agents\IngestAgent;
use App\Services\TokenUsageLogger;
use App\Services\WikiConfig;
use App\Services\WikiFileService;
use Illuminate\Console\Command;
use Laravel\Ai\Exceptions\FailoverableException;
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

        try {
            /** @var StructuredAgentResponse $response */
            $response = $agent->prompt(
                "Извлеки знания из следующего документа:\n\n{$rawContent}",
                provider: $provider,
                model: $model
            );
        } catch (FailoverableException $e) {
            $fallback = $wikiConfig->getFallbackProvider();
            $this->warn("Основной провайдер недоступен. Переключаюсь на fallback: {$fallback['provider']}/{$fallback['model']}");

            /** @var StructuredAgentResponse $response */
            $response = $agent->prompt(
                "Извлеки знания из следующего документа:\n\n{$rawContent}",
                provider: $fallback['provider'],
                model: $fallback['model']
            );

            $provider = $fallback['provider'];
            $model = $fallback['model'];
        }

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
        $pages = $structured['pages'] ?? [];

        $createdSlugs = [];
        $updatedSlugs = [];

        foreach ($pages as $page) {
            $wasUpdated = $fileService->writeWikiPage($userspace, $page, [$filename]);

            if ($wasUpdated) {
                $updatedSlugs[] = $page['slug'];
            } else {
                $createdSlugs[] = $page['slug'];
            }
        }

        if (! empty($createdSlugs)) {
            $this->line('Созданы страницы:');
            foreach ($createdSlugs as $slug) {
                $category = collect($pages)->firstWhere('slug', $slug)['category'] ?? '';
                $this->line("  - {$slug} ({$category})");
            }
        }

        if (! empty($updatedSlugs)) {
            $this->line('Обновлены страницы:');
            foreach ($updatedSlugs as $slug) {
                $category = collect($pages)->firstWhere('slug', $slug)['category'] ?? '';
                $this->line("  - {$slug} ({$category})");
            }
        }

        $fileService->updateIndex($userspace, $filename, $overallSummary, $pages);
        $this->line('index.md обновлён');

        $fileService->appendIngestLog(
            $userspace,
            $filename,
            $createdSlugs,
            $updatedSlugs,
            $provider,
            $model,
            $inputTokens + $outputTokens,
            $totalCost
        );
        $this->line('log.md обновлён');

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
