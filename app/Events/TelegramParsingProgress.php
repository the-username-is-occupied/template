<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\SourceDraft;
use Illuminate\Foundation\Events\Dispatchable;

class TelegramParsingProgress
{
    use Dispatchable;

    public function __construct(
        public SourceDraft $draft,
        public int $postsParsed,
        public int $linksDiscovered,
    ) {}
}
