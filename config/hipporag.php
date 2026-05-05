<?php

declare(strict_types=1);

return [
    'api_url' => env('HIPPORAG_API_URL', 'http://hipporag-api:8000'),
    'internal_api_url' => env('HIPPORAG_INTERNAL_API_URL', 'http://hipporag-api:8000'),
    'timeout' => env('HIPPORAG_HTTP_TIMEOUT', 300),
    'work_dir_prefix' => env('HIPPORAG_WORK_DIR_PREFIX', '/app/data'),
    'default_model' => env('HIPPORAG_LLM_MODEL', 'gpt-4o-mini'),
    'default_embedding_model' => env('HIPPORAG_EMBEDDING_MODEL', 'nvidia/NV-Embed-v2'),
    'llm_base_url' => env('HIPPORAG_LLM_BASE_URL'),
    'llm_api_key' => env('HIPPORAG_LLM_API_KEY', env('FREELLMAPI_API_KEY')),
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
