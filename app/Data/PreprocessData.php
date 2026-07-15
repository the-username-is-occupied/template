<?php

declare(strict_types=1);

namespace App\Data;

use App\Enums\PreprocessStatus;
use Spatie\LaravelData\Attributes\Validation\Required;
use Spatie\LaravelData\Data;

class PreprocessData extends Data
{
    public function __construct(
        #[Required]
        public PreprocessStatus $decision,
        #[Required]
        public string $reason,
        #[Required]
        public string $suggested_short_reply,
    ) {}
}
