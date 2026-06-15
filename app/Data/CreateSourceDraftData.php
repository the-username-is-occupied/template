<?php

declare(strict_types=1);

namespace App\Data;

use Spatie\LaravelData\Attributes\Validation\Required;
use Spatie\LaravelData\Attributes\Validation\StringType;
use Spatie\LaravelData\Attributes\Validation\Uuid;
use Spatie\LaravelData\Data;

class CreateSourceDraftData extends Data
{
    public function __construct(
        #[Required, Uuid]
        public string $knowledge_base_id,
        #[Required, StringType]
        public string $raw_input,
    ) {}
}
