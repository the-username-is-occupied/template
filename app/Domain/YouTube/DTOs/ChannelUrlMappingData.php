<?php

declare(strict_types=1);

namespace App\Domain\YouTube\DTOs;

use App\Domain\Share\DTOs\BaseDTO;

class ChannelUrlMappingData extends BaseDTO
{
    public function __construct(
        public string $url,
        public ?string $handle,
    ) {}
}
