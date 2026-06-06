<?php

declare(strict_types=1);

namespace App\Domain\NotebookLM\DTOs;

use Spatie\LaravelData\Data;

class HealthStatusDTO extends Data
{
    public function __construct(
        public string $account_id,
        public ?int $mtime_age_seconds = null,
        public string $mtime = 'unknown',
        public bool $is_connected = false,
        public string $status = 'unknown',
    ) {}
}
