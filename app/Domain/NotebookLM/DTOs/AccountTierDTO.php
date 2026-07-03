<?php

declare(strict_types=1);

namespace App\Domain\NotebookLM\DTOs;

use Spatie\LaravelData\Data;

class AccountTierDTO extends Data
{
    public function __construct(
        public string $tier,
        public bool $is_paid,
    ) {}
}
