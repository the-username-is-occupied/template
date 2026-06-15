<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\ContentSource;
use Illuminate\Foundation\Events\Dispatchable;

class ExtractionCompleted
{
    use Dispatchable;

    public function __construct(
        public ContentSource $source,
        public bool $success = true,
        public string $errorMessage = '',
    ) {}
}
