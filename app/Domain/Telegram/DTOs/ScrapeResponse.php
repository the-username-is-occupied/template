<?php

declare(strict_types=1);

namespace App\Domain\Telegram\DTOs;

use Spatie\LaravelData\Data;

class ScrapeResponse extends Data
{
    public function __construct(
        public string $status,
        public string $channel,
        public string $content_source_id,
    ) {}
}
