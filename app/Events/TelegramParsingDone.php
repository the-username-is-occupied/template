<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\SourceDraft;
use Illuminate\Foundation\Events\Dispatchable;

class TelegramParsingDone
{
    use Dispatchable;

    public function __construct(
        public SourceDraft $draft,
        public int $totalPosts,
        public int $totalLinks,
    ) {}
}
