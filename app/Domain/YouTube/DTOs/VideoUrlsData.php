<?php

declare(strict_types=1);

namespace App\Domain\YouTube\DTOs;

use App\Domain\Share\DTOs\BaseDTO;

class VideoUrlsData extends BaseDTO
{
    public function __construct(
        /** @var list<string> */
        public array $urls,
    ) {}
}
