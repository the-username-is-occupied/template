<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

class LlmModelCatalogService
{
    /**
     * @return array{models: array<int, array{name: string, provider: string}>, warnings: array<int, string>}
     */
    public function models(): array
    {
        $cacheKey = 'hipporag.model_catalog.v1';
        $ttlSeconds = max(30, (int) config('hipporag.model_catalog_cache_seconds', 180));

        return Cache::remember($cacheKey, now()->addSeconds($ttlSeconds), function (): array {
            $warnings = [];
            $providers = ['freellmapi', 'openai'];
            $models = [];

            foreach ($providers as $provider) {
                try {
                    $models = array_merge($models, $this->fetchProviderModels($provider));
                } catch (Throwable $throwable) {
                    report($throwable);
                    $warnings[] = sprintf('Failed to load models from %s.', $provider);

                    if ($provider === 'freellmapi') {
                        $warnings[] = 'If the FreeLLMAPI key is invalid, check dashboard key on :3001 (Key tab).';
                    }
                }
            }

            $uniqueByName = [];
            foreach ($models as $row) {
                $name = $row['name'];
                if (! isset($uniqueByName[$name])) {
                    $uniqueByName[$name] = $row;
                }
            }

            if ($uniqueByName === []) {
                $fallbackModel = (string) config('hipporag.default_model', 'auto');
                $fallbackProvider = (string) config('hipporag.default_provider', 'freellmapi');
                $uniqueByName[$fallbackModel] = [
                    'name' => $fallbackModel,
                    'provider' => $fallbackProvider,
                ];
                $warnings[] = 'Model catalogs are unavailable. Using fallback model list.';
            }

            ksort($uniqueByName, SORT_NATURAL | SORT_FLAG_CASE);

            return [
                'models' => array_values($uniqueByName),
                'warnings' => $warnings,
            ];
        });
    }

    public function resolveProviderForModel(string $modelName): string
    {
        foreach ($this->models()['models'] as $row) {
            if ($row['name'] === $modelName) {
                return $row['provider'];
            }
        }

        return (string) config('hipporag.default_provider', 'freellmapi');
    }

    /**
     * @return array<int, array{name: string, provider: string}>
     */
    private function fetchProviderModels(string $provider): array
    {
        $baseUrl = trim((string) config("ai.providers.{$provider}.url"), '/');
        if ($baseUrl === '') {
            throw new RuntimeException(sprintf('Provider %s URL is not configured.', $provider));
        }

        $apiKey = trim((string) config("ai.providers.{$provider}.key"));
        $request = Http::acceptJson()->timeout(15);
        if ($apiKey !== '') {
            $request = $request->withToken($apiKey);
        }

        $response = $request->get($baseUrl.'/models');
        if ($response->failed()) {
            throw new RuntimeException(sprintf(
                'Provider %s returned HTTP %d for /models.',
                $provider,
                $response->status()
            ));
        }

        $payload = $response->json();
        if (! is_array($payload)) {
            throw new RuntimeException(sprintf('Provider %s returned malformed models payload.', $provider));
        }

        $rows = [];
        foreach (($payload['data'] ?? []) as $entry) {
            $id = trim((string) ($entry['id'] ?? ''));
            if ($id === '') {
                continue;
            }

            $rows[] = [
                'name' => $id,
                'provider' => $provider,
            ];
        }

        return $rows;
    }
}
