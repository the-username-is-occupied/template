<?php

declare(strict_types=1);

namespace App\Domain\NotebookLM\DTOs;

use Spatie\LaravelData\Data;

class NotebookMetadataDTO extends Data
{
    /** @var NotebookMetadataSourceDTO[] */
    public array $sources = [];

    public function __construct(
        public NotebookDTO $notebook,
        array $sources = [],
    ) {
        $this->sources = $sources;
    }
}
