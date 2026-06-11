<?php

namespace App\Domain\DTOs\TG;

use Spatie\LaravelData\Data;

class ChannelInfoResponse extends Data
{
    public function __construct(
        public string $channel,
        public string $title,
        public ?string $description,
        public ?string $members,
        public ?string $avatar_url,
        public ?string $content_source_id = null,
    ) {}
}
