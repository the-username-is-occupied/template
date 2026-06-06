<?php

declare(strict_types=1);

namespace App\Domain\NotebookLM\DTOs;

use Spatie\LaravelData\Data;

class ShareStatusDTO extends Data
{
    public function __construct(
        public string $notebook_id,
        public bool $is_public,
        public string $access,
        public string $view_level,
        /** @var SharedUserDTO[] */
        public array $shared_users = [],
        public ?string $share_url = null,
    ) {}
}
