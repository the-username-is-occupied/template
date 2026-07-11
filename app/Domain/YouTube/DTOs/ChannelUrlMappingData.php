<?php

declare(strict_types=1);

namespace App\Domain\YouTube\DTOs;

use App\Domain\Share\DTOs\BaseDTO;

class ChannelUrlMappingData extends BaseDTO
{
    public function __construct(
        public string $url,
        public ?string $handle = null,
        public ?string $videoId = null,
        public ?string $title = null,
        public ?string $description = null,
        public ?array $tags = null,
        public ?string $channelId = null,
        public ?string $channelTitle = null,
        public ?string $publishedAt = null,
        public ?string $duration = null,
        public ?int $viewCount = null,
        public ?int $likeCount = null,
        public ?int $commentCount = null,
    ) {}
}
