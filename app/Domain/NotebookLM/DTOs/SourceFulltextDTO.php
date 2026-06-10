<?php

declare(strict_types=1);

namespace App\Domain\NotebookLM\DTOs;

use Spatie\LaravelData\Data;

class SourceFulltextDTO extends Data
{
    public function __construct(
        public string $source_id,
        public string $title,
        public string $content,
        public ?string $url,
        public int $char_count,
        public string $format = 'markdown',
    ) {}
}
