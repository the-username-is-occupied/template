<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessTelegramChunkJob;
use App\Jobs\TelegramScrapingDoneJob;
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
            'posts.*.url' => ['sometimes', 'string', 'url'],
            'posts.*.date' => ['sometimes', 'string'],
            'posts.*.text' => ['sometimes', 'string'],
            'posts.*.text_html' => ['sometimes', 'string'],
            'posts.*.links' => ['sometimes', 'array'],
            'posts.*.views' => ['sometimes', 'string'],
            'posts.*.type' => ['sometimes', 'string'],
            'posts.*.reactions' => ['sometimes', 'array'],
        ]);

        $action = $validated['action'];
        $contentSourceId = $validated['content_source_id'];

        Log::info('Telegram webhook received', [
            'action' => $action,
            'content_source_id' => $contentSourceId,
        ]);

        match ($action) {
            'upload' => $this->handleUpload($contentSourceId, $validated['posts'] ?? []),
            'done' => $this->handleDone($contentSourceId),
            default => null,
        };

        return response()->noContent(200);
    }

    private function handleUpload(string $contentSourceId, array $posts): void
    {
        if (empty($posts)) {
            Log::warning('Received upload action with empty posts array', [
                'content_source_id' => $contentSourceId,
            ]);

            return;
        }

        // Dispatch job to process the chunk
        ProcessTelegramChunkJob::dispatch($contentSourceId, $posts);

        Log::info('Dispatched ProcessTelegramChunkJob', [
            'content_source_id' => $contentSourceId,
            'posts_count' => count($posts),
        ]);
    }

    private function handleDone(string $contentSourceId): void
    {
        // Dispatch job to finalize scraping
        TelegramScrapingDoneJob::dispatch($contentSourceId);

        Log::info('Dispatched TelegramScrapingDoneJob', [
            'content_source_id' => $contentSourceId,
        ]);
    }
}
