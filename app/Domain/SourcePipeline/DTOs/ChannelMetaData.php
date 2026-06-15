<?php

declare(strict_types=1);

namespace App\Domain\SourcePipeline\DTOs;

use Spatie\LaravelData\Data;

class ChannelMetaData extends Data
{
    public function __construct(
        public string $title,
        public ?string $description = null,
        public ?string $members = null,
        public ?string $avatar_url = null,
    ) {}
}
