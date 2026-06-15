<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\SourceDraft;
use Illuminate\Foundation\Events\Dispatchable;

class YoutubeVideosLoaded
{
    use Dispatchable;

    public function __construct(
        public readonly SourceDraft $draft,
        public readonly array $videos,
    ) {}
}
