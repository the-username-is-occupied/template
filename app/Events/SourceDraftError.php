<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\SourceDraft;
use Illuminate\Foundation\Events\Dispatchable;

class SourceDraftError
{
    use Dispatchable;

    public function __construct(
        public SourceDraft $draft,
        public string $code,
        public string $message,
    ) {}
}
