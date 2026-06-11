<?php

namespace App\Services\TG;

use App\Domain\DTOs\TG\ChannelInfoResponse;
use App\Domain\DTOs\TG\PostResponse;
use App\Domain\DTOs\TG\ScrapeResponse;
use App\Domain\DTOs\TG\StatusResponse;
use Illuminate\Support\Facades\Http;

class TGScraperService
{
    private string $baseUrl;

    public function __construct()
    {
        $this->baseUrl = config('tg-scraper.tg_scraper.base_url') ?? 'http://tg:8000';
    }

    /**
     * Get the current status of the scraper
     */
    public function getStatus(): StatusResponse
    {
        $response = Http::get("{$this->baseUrl}/status");

        return new StatusResponse(...$response->json());
    }

    /**
     * Start scraping a Telegram channel
     */
    public function scrape(
        string $contentSourceId,
        string $channel,
        int $limit = 0,
        ?int $fromId = null,
        ?int $toId = null,
        ?\DateTimeInterface $fromDate = null,
        ?\DateTimeInterface $toDate = null,
        int $workers = 3,
        int $chunkLimit = 2000,
        string $hookUrl = 'app/hook'
    ): ScrapeResponse {
        $data = [
            'content_source_id' => $contentSourceId,
            'channel' => $channel,
            'limit' => $limit,
            'workers' => $workers,
            'chunk_limit' => $chunkLimit,
            'hook_url' => $hookUrl,
        ];

        if ($fromId !== null) {
            $data['from_id'] = $fromId;
        }

        if ($toId !== null) {
            $data['to_id'] = $toId;
        }

        if ($fromDate !== null) {
            $data['from_date'] = $fromDate->format('Y-m-d');
        }

        if ($toDate !== null) {
            $data['to_date'] = $toDate->format('Y-m-d');
        }

        $response = Http::post("{$this->baseUrl}/scrape", $data);

        return new ScrapeResponse(...$response->json());
    }

    /**
     * Get information about a Telegram channel
     */
    public function getChannelInfo(string $channel, ?string $contentSourceId = null): ChannelInfoResponse
    {
        $url = "{$this->baseUrl}/channel/{$channel}";

        if ($contentSourceId !== null) {
            $url .= "?content_source_id={$contentSourceId}";
        }

        $response = Http::get($url);

        return new ChannelInfoResponse(...$response->json());
    }

    /**
     * Get a specific post from a Telegram channel
     */
    public function getPost(string $channel, int $postId): PostResponse
    {
        $response = Http::get("{$this->baseUrl}/post/{$channel}/{$postId}");

        return new PostResponse(...$response->json());
    }
}
