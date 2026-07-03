<?php

declare(strict_types=1);

namespace App\Domain\NotebookLM\DTOs;

use Spatie\LaravelData\Data;

class AccountLimitsDTO extends Data
{
    public function __construct(
        public int $notebooks_limit,
        public int $sources_per_notebook_limit,
        public int $chats_per_day_limit,
    ) {}
}
