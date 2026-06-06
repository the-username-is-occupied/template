<?php

declare(strict_types=1);

namespace App\Domain\NotebookLM\DTOs;

use Spatie\LaravelData\Data;

class SourceDTO extends Data
{
    public function __construct(
        public string $id,
        public string $title,
        public ?string $url,
        public string $created_at,
        public string $status,
        public string $kind,
        public bool $is_ready = false,
        public bool $is_processing = false,
        public bool $is_error = false,
    ) {}
}
