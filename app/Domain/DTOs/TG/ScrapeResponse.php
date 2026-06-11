<?php

namespace App\Domain\DTOs\TG;

use Spatie\LaravelData\Data;

class ScrapeResponse extends Data
{
    public function __construct(
        public string $status,
        public string $channel,
        public string $content_source_id,
    ) {}
}
