<?php

declare(strict_types=1);

return [
    'testing_provider' => env('WIKI_TESTING_PROVIDER', 'openai'),
    'testing_model' => env('WIKI_TESTING_MODEL', 'gpt-4o-mini'),
    'production_provider' => env('WIKI_PRODUCTION_PROVIDER', 'openai'),
    'production_model' => env('WIKI_PRODUCTION_MODEL', 'gpt-4o'),
    'fallback_provider' => env('WIKI_FALLBACK_PROVIDER', 'anthropic'),
    'fallback_model' => env('WIKI_FALLBACK_MODEL', 'claude-haiku-4-5-20251001'),
];
