<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Ai\Agents\QueryAgent;
use App\Services\TokenUsageLogger;
use App\Services\WikiConfig;
use App\Services\WikiFileService;
use Illuminate\Console\Command;
use Laravel\Ai\Exceptions\FailoverableException;

class WikiAsk extends Command
{
    protected $signature = 'wiki:ask
        {userspace : The userspace/project slug}
        {question : The question to ask the wiki}
        {--production : Use production-grade models}
        {--provider= : Manually override the AI provider}';

    protected $description = 'Ask a question answered from the wiki knowledge base';

    public function handle(
        WikiConfig $wikiConfig,
        WikiFileService $fileService,
        TokenUsageLogger $usageLogger
    ): int {
        $userspace = $this->argument('userspace');
        $question = $this->argument('question');

        $providerConfig = $this->resolveProvider($wikiConfig);
        $provider = $providerConfig['provider'];
        $model = $providerConfig['model'];

        $this->line('Провайдер: '.ucfirst($provider));
        $this->line("Модель: {$model}");

        $agent = new QueryAgent($userspace);

        try {
            $response = $agent->prompt($question, provider: $provider, model: $model);
        } catch (FailoverableException $e) {
            $fallback = $wikiConfig->getFallbackProvider();
            $this->warn("Основной провайдер недоступен. Переключаюсь на fallback: {$fallback['provider']}/{$fallback['model']}");

            $response = $agent->prompt($question, provider: $fallback['provider'], model: $fallback['model']);

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
        $this->line('---');
        $this->line($response->text);
        $this->line('---');
        $this->newLine();

        $usageLogger->log($response, [
            'userspace' => $userspace,
            'operation' => 'query',
            'provider' => $provider,
            'model' => $model,
            'source_file' => null,
        ]);

        if ($this->confirm('Сохранить ответ в вики?', false)) {
            $slug = $this->ask('Введите slug для страницы');
            $title = $this->ask('Введите заголовок страницы', $question);

            $fileService->saveQueryAnswer($userspace, $slug, $title, $response->text);

            $this->info("Страница [[{$slug}]] сохранена.");
            $this->line('index.md обновлён');
            $this->line('log.md обновлён');
        }

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
