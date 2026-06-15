<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\SourceDraftService;
use Illuminate\Contracts\Queue\ShouldQueue;

class FetchSourceMetaJob implements ShouldQueue
{
    public function __construct(
        public string $draftId,
    ) {}

    /**
     * Thin job: delegates all business logic to SourceDraftService.
     */
    public function handle(SourceDraftService $draftService): void
    {
        $draftService->fetchMeta($this->draftId);
    }
}
