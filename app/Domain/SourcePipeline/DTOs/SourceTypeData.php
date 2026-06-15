<?php

declare(strict_types=1);

namespace App\Domain\SourcePipeline\DTOs;

use App\Enums\SourceType;
use Spatie\LaravelData\Data;

class SourceTypeData extends Data
{
    public function __construct(
        public SourceType $type,
        public string $normalizedId,
    ) {}
}
