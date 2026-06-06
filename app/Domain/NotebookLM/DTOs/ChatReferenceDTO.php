<?php

declare(strict_types=1);

namespace App\Domain\NotebookLM\DTOs;

use Spatie\LaravelData\Data;

class ChatReferenceDTO extends Data
{
    public function __construct(
        public string $source_id,
        public int $citation_number,
        public string $cited_text,
        public int $start_char,
        public int $end_char,
        public ?string $chunk_id = null,
    ) {}
}
