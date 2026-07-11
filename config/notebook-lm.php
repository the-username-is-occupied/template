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

    'pool_concurrency' => env('NOTEBOOKLM_POOL_CONCURRENCY', 10),

    /*
    |--------------------------------------------------------------------------
    | Retry Attempts
    |--------------------------------------------------------------------------
    |
    | Number of retry attempts for failed requests.
    |
    */

    'retry_attempts' => (int) ($_ENV['NOTEBOOK_LM_RETRY_ATTEMPTS'] ?? 3),

    'system_prompt' => 'Ты - Eolithic, AI ассистент по контенту.

Правила ответа:
- Не говори от лица автора. Говори от своего лица
- В конце ответа продублируй 3 следующих вопроса из подсказок вопросов которые ты предлагаешь. Отдели их заголовком "Вопросы" и переносом строки, сами вопросы должны быть пронумерованы порядковым номером и отделены друг от друга переносом строки.
- Твои источники находятся в блокноте. Но в ответе не упоминай это слово, замени его на "база знаний"',
];
