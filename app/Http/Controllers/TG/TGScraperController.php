<?php

declare(strict_types=1);

namespace App\Http\Controllers\TG;

use App\Domain\Telegram\TGScraperService;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class TGScraperController extends Controller
{
    public function __construct(
        private readonly TGScraperService $tgScraperService
    ) {}

    public function status()
    {
        $status = $this->tgScraperService->getStatus();

        return response()->json($status);
    }

    public function scrape(Request $request)
    {
        $validated = $request->validate([
            'content_source_id' => 'required|string',
            'channel' => 'required|string',
            'limit' => 'integer|nullable',
            'from_id' => 'integer|nullable',
            'to_id' => 'integer|nullable',
            'from_date' => 'date|nullable',
            'to_date' => 'date|nullable',
            'workers' => 'integer|min:1|max:5|nullable',
            'chunk_limit' => 'integer|min:1|nullable',
            'hook_url' => 'required|url',
        ]);

        $scrapeResponse = $this->tgScraperService->scrape(
            $validated['content_source_id'],
            $validated['channel'],
            $validated['limit'] ?? 0,
            $validated['from_id'] ?? null,
            $validated['to_id'] ?? null,
            $validated['from_date'] ?? null,
            $validated['to_date'] ?? null,
            $validated['workers'] ?? 3,
            $validated['chunk_limit'] ?? 2000,
            $validated['hook_url']
        );

        return response()->json($scrapeResponse, 202);
    }

    public function channelInfo(string $channel, Request $request)
    {
        $contentSourceId = $request->input('content_source_id');

        $channelInfo = $this->tgScraperService->getChannelInfo($channel, $contentSourceId);

        return response()->json($channelInfo);
    }

    public function post(string $channel, int $postId)
    {
        $post = $this->tgScraperService->getPost($channel, $postId);

        return response()->json($post);
    }
}
