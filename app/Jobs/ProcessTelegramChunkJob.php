<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\ContentSource;
use App\Services\TelegramChunkService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class ProcessTelegramChunkJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public string $contentSourceId,
        public array $posts,
    ) {}

    public function handle(): void
    {
        $source = ContentSource::find($this->contentSourceId);

        if (! $source) {
            Log::error('ContentSource not found for chunk processing', [
                'content_source_id' => $this->contentSourceId,
            ]);

            return;
        }

        /** @var TelegramChunkService $service */
        $service = app(TelegramChunkService::class);

        // Process the chunk
        $service->processChunk($source, $this->posts);

        // Finalize chunk (decrement counter, check if done)
        $service->finalizeChunk($source);
    }
}
