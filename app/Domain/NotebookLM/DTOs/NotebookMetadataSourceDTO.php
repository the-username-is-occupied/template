<?php

declare(strict_types=1);

namespace App\Domain\NotebookLM\DTOs;

use Spatie\LaravelData\Data;

class NotebookMetadataSourceDTO extends Data
{
    public function __construct(
        public ?string $id,
        public ?string $kind,
        public ?string $title,
        public ?string $url,
    ) {}
}
