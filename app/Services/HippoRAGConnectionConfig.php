<?php

declare(strict_types=1);

namespace App\Services;

use RuntimeException;

class HippoRAGConnectionConfig
{
    /**
     * @return array{
     *     llm_model_name: string,
     *     llm_provider: string,
     *     llm_base_url: string,
     *     llm_api_key: string,
     *     embedding_model_name: string,
     *     embedding_base_url: string,
     *     embedding_api_key: string
     * }
     */
    public function build(string $llmModelName, ?string $llmProvider = null): array
    {
        $provider = $this->resolveProvider($llmModelName, $llmProvider);

        return [
            'llm_model_name' => $llmModelName,
            'llm_provider' => $provider,
            'llm_base_url' => $this->requiredProviderConfig($provider, 'url'),
            'llm_api_key' => $this->requiredProviderConfig($provider, 'key'),
            'embedding_model_name' => $this->requiredConfig('hipporag.default_embedding_model'),
            'embedding_base_url' => $this->requiredConfig('hipporag.embedding_base_url'),
            'embedding_api_key' => $this->requiredConfig('hipporag.embedding_api_key'),
        ];
    }

    private function resolveProvider(string $llmModelName, ?string $llmProvider): string
    {
        $normalizedProvider = trim((string) $llmProvider);
        if ($normalizedProvider !== '') {
            return $normalizedProvider;
        }

        if (str_contains($llmModelName, '::')) {
            [$provider] = explode('::', $llmModelName, 2);
            $provider = trim($provider);
            if ($provider !== '') {
                return $provider;
            }
        }

        return (string) config('hipporag.default_provider', 'freellmapi');
    }

    private function requiredConfig(string $key): string
    {
        $value = trim((string) config($key, ''));
        if ($value === '') {
            throw new RuntimeException(sprintf('HippoRAG config value "%s" is required.', $key));
        }

        return $value;
    }

    private function requiredProviderConfig(string $provider, string $field): string
    {
        $configKey = sprintf('ai.providers.%s.%s', $provider, $field);
        $value = trim((string) config($configKey, ''));
        if ($value === '') {
            throw new RuntimeException(sprintf('HippoRAG config value "%s" is required.', $configKey));
        }

        return $value;
    }
}
