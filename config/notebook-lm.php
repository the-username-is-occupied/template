<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | FastAPI Service URL
    |--------------------------------------------------------------------------
    |
    | The base URL for the FastAPI service that wraps notebooklm-py.
    | This service runs in the notebooklm Docker container.
    |
    */

    'url' => $_ENV['NOTEBOOK_LM_URL'] ?? 'http://notebooklm:8000',

    /*
    |--------------------------------------------------------------------------
    | Request Timeout
    |--------------------------------------------------------------------------
    |
    | Timeout in seconds for HTTP requests to the FastAPI service.
    |
    */

    'timeout' => (int) ($_ENV['NOTEBOOK_LM_TIMEOUT'] ?? 30),

    /*
    |--------------------------------------------------------------------------
    | Retry Attempts
    |--------------------------------------------------------------------------
    |
    | Number of retry attempts for failed requests.
    |
    */

    'retry_attempts' => (int) ($_ENV['NOTEBOOK_LM_RETRY_ATTEMPTS'] ?? 3),
];
