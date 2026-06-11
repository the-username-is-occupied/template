<?php

declare(strict_types=1);

namespace App\Domain\Telegram\DTOs;

use Spatie\LaravelData\Data;

class StatusResponse extends Data
{
    public function __construct(
        public bool $busy,
        public ?string $channel,
        public ?string $content_source_id,
        public ?string $started_at,
    ) {}
}
