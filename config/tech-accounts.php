<?php

declare(strict_types=1);

return [
    'cookie_base_path' => $_ENV['TECH_ACCOUNTS_COOKIE_BASE_PATH'] ?? $_SERVER['TECH_ACCOUNTS_COOKIE_BASE_PATH'] ?? storage_path('app/cookies'),
];
