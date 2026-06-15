<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\SourceIndexingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessSourceJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 60;

    public function __construct(
        public string $contentSourceId,
    ) {}

    public function uniqueId(): string
    {
        return $this->contentSourceId;
    }

    public function handle(): void
    {
        app(SourceIndexingService::class)->process($this->contentSourceId);
    }
}
