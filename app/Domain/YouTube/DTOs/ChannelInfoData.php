<?php

declare(strict_types=1);

namespace App\Domain\YouTube\DTOs;

use App\Domain\Share\DTOs\BaseDTO;

class ChannelInfoData extends BaseDTO
{
    public function __construct(
        public string $id,
        public string $title,
        public ?string $description,
        public ?string $handle,
        public ?string $avatar_url,
        public ?string $published_at,
        public int $subscribers_count,
        public int $view_count,
        public int $video_count,
    ) {}
}
