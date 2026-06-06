<?php

declare(strict_types=1);

namespace App\Domain\NotebookLM\DTOs;

use Spatie\LaravelData\Data;

class NotebookDTO extends Data
{
    public function __construct(
        public string $id,
        public string $title,
        public string $created_at,
        public int $sources_count = 0,
        public bool $is_owner = true,
    ) {}
}
