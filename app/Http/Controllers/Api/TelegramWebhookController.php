<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessTelegramChunkJob;
use App\Models\ContentSource;
use App\Services\TelegramChunkService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

class TelegramWebhookController extends Controller
{
    /**
     * Handle incoming webhook from TG Scraper.
     *
     * Expected actions:
     * - upload: Receive a batch of posts
     * - done: Scraping completed
     */
    public function handle(Request $request): Response
    {
        // Validate required fields
        $validated = $request->validate([
            'action' => ['required', 'string', 'in:upload,done'],
            'content_source_id' => ['required', 'string', 'uuid'],
            'posts' => ['sometimes', 'array'],
            'posts.*.id' => ['sometimes', 'integer'],
            'posts.*.url' => ['sometimes', 'nullable', ],
            'posts.*.date' => ['sometimes', 'nullable', ],
            'posts.*.text' => ['sometimes', 'nullable', ],
            'posts.*.text_html' => ['sometimes', 'nullable',],
            'posts.*.links' => ['sometimes', 'array'],
            'posts.*.views' => ['sometimes', 'nullable', 'string'],
            'posts.*.type' => ['sometimes', 'nullable', 'string'],
            'posts.*.reactions' => ['sometimes', 'array'],
        ]);

        $action = $validated['action'];
        $contentSourceId = $validated['content_source_id'];

        Log::info('Telegram webhook received', [
            'action' => $action,
            'content_source_id' => $contentSourceId,
        ]);

        /** @var TelegramChunkService $chunkService */
        $chunkService = app(TelegramChunkService::class);

        match ($action) {
            'upload' => $this->handleUpload($chunkService, $contentSourceId, $validated['posts'] ?? []),
            'done' => $this->handleDone($chunkService, $contentSourceId),
            default => null,
        };

        return response()->noContent(200);
    }

    private function handleUpload(TelegramChunkService $chunkService, string $contentSourceId, array $posts): void
    {
        if (empty($posts)) {
            Log::warning('Received upload action with empty posts array', [
                'content_source_id' => $contentSourceId,
            ]);

            return;
        }

        // Increment pending chunks counter in Redis
        $chunkService->incrementPendingChunks($contentSourceId);

        // Dispatch job to process the chunk
        ProcessTelegramChunkJob::dispatch($contentSourceId, $posts);

        Log::info('Dispatched ProcessTelegramChunkJob', [
            'content_source_id' => $contentSourceId,
            'posts_count' => count($posts),
        ]);
    }

    private function handleDone(TelegramChunkService $chunkService, string $contentSourceId): void
    {
        // Set scraping done flag in Redis
        $chunkService->setScrapingDone($contentSourceId);

        // Check if there are no pending chunks
        $pendingChunks = $chunkService->getPendingChunksCount($contentSourceId);

        if ($pendingChunks <= 0) {
            // All chunks are processed, execute done logic immediately
            $source = ContentSource::find($contentSourceId);

            if ($source) {
                $chunkService->executeDoneLogic($source);
            }
        } else {
            Log::info('Scraping done flag set, waiting for pending chunks to complete', [
                'content_source_id' => $contentSourceId,
                'pending_chunks' => $pendingChunks,
            ]);
        }
    }
}
