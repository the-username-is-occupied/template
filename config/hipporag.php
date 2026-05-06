<?php

declare(strict_types=1);

return [
    'api_url' => env('HIPPORAG_API_URL', 'http://hipporag-api:8000'),
    'internal_api_url' => env('HIPPORAG_INTERNAL_API_URL', 'http://hipporag-api:8000'),
    'timeout' => env('HIPPORAG_HTTP_TIMEOUT', 300),
    'work_dir_prefix' => env('HIPPORAG_WORK_DIR_PREFIX', '/app/data'),
    'default_provider' => env('HIPPORAG_DEFAULT_PROVIDER', 'freellmapi'),
    'default_model' => env('HIPPORAG_LLM_MODEL', 'auto'),
    'default_embedding_model' => env('HIPPORAG_EMBEDDING_MODEL', 'text-embedding-3-small'),
    'llm_base_url' => env('HIPPORAG_LLM_BASE_URL', env('FREELLMAPI_INTERNAL_URL')),
    'llm_api_key' => env('HIPPORAG_LLM_API_KEY', env('FREELLMAPI_API_KEY', env('OPENAI_API_KEY'))),
    'embedding_base_url' => env('HIPPORAG_EMBEDDING_BASE_URL', env('OPENAI_URL', 'https://api.openai.com/v1')),
    'embedding_api_key' => env('HIPPORAG_EMBEDDING_API_KEY', env('OPENAI_API_KEY')),
    'score_threshold' => (float) env('HIPPORAG_SCORE_THRESHOLD', 0.4),
    'chunk_size' => (int) env('HIPPORAG_CHUNK_SIZE', 512),
    'chunk_overlap_ratio' => (float) env('HIPPORAG_CHUNK_OVERLAP_RATIO', 0.12),
    'model_catalog_cache_seconds' => (int) env('HIPPORAG_MODEL_CATALOG_CACHE_SECONDS', 180),
    'agent' => [
        'default_instructions' => env(
            'HIPPORAG_AGENT_DEFAULT_INSTRUCTIONS',
            'You are the Laravel AI Ask Agent. Answer using only provided sources. If evidence is missing, say so explicitly.',
        ),
    ],
    'token_pricing' => [
        'gpt-4o-mini' => [
            'prompt' => 0.000150,
            'completion' => 0.000600,
        ],
        'deepseek-chat' => [
            'prompt' => 0.000014,
            'completion' => 0.000028,
        ],
    ],
];
