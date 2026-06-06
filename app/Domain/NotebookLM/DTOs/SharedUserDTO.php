<?php

declare(strict_types=1);

namespace App\Domain\NotebookLM\DTOs;

use Spatie\LaravelData\Data;

class SharedUserDTO extends Data
{
    public function __construct(
        public string $email,
        public string $permission,
        public ?string $display_name = null,
        public ?string $avatar_url = null,
    ) {}
}
