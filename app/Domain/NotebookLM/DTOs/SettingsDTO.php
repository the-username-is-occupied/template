<?php

declare(strict_types=1);

namespace App\Domain\NotebookLM\DTOs;

use Spatie\LaravelData\Data;

class SettingsDTO extends Data
{
    public function __construct(
        public ?string $output_language,
        public AccountLimitsDTO $account_limits,
        public AccountTierDTO $account_tier,
    ) {}
}
