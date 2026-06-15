<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\ContentSource;
use Illuminate\Foundation\Events\Dispatchable;

class ExtractionDone
{
    use Dispatchable;

    public function __construct(
        public readonly ContentSource $source,
    ) {}
}
