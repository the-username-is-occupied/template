<?php

declare(strict_types=1);

namespace App\Domain\Telegram;

use App\Domain\Telegram\DTOs\ChannelInfoResponse;
use App\Domain\Telegram\DTOs\PostResponse;
use App\Domain\Telegram\DTOs\ScrapeResponse;
use App\Domain\Telegram\DTOs\StatusResponse;
use DateTimeInterface;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class TGScraperService
{
    private string $baseUrl;

    public function __construct()
    {
        $this->baseUrl = config('tg-scraper.base_url') ?? 'http://telegramm:8000';
    }

    public function getBaseUrl(): string
    {
        return $this->baseUrl;
    }

    public function getStatus(): StatusResponse
    {
        $response = Http::get("{$this->baseUrl}/status");

        if ($response->failed()) {
            throw new RuntimeException('Failed to fetch TG scraper status.');
        }

        return new StatusResponse(...$response->json());
    }

    public function scrape(
        string $contentSourceId,
        string $channel,
        int $limit = 0,
        ?int $fromId = null,
        ?int $toId = null,
        ?DateTimeInterface $fromDate = null,
        ?DateTimeInterface $toDate = null,
        int $workers = 3,
        int $chunkLimit = 2000,
        string $hookUrl = 'http://app:80/api/webhooks/telegram-scraper'
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

        if ($fromDate instanceof DateTimeInterface) {
            $data['from_date'] = $fromDate->format('Y-m-d');
        }

        if ($toDate instanceof DateTimeInterface) {
            $data['to_date'] = $toDate->format('Y-m-d');
        }

        $response = Http::post("{$this->baseUrl}/scrape", $data);

        if ($response->failed()) {
            throw new RuntimeException('Failed to start TG scrape.');
        }

        return new ScrapeResponse(...$response->json());
    }

    public function getChannelInfo(string $channel, ?string $contentSourceId = null): ChannelInfoResponse
    {
        $url = "{$this->baseUrl}/channel/{$channel}";

        if ($contentSourceId !== null) {
            $url .= "?content_source_id={$contentSourceId}";
        }

        $response = Http::get($url);

        if ($response->failed()) {
            throw new RuntimeException("Failed to fetch TG channel info for: {$channel}");
        }

        return new ChannelInfoResponse(...$response->json());
    }

    public function getPost(string $channel, int $postId): PostResponse
    {
        $response = Http::get("{$this->baseUrl}/post/{$channel}/{$postId}");

        if ($response->failed()) {
            throw new RuntimeException("Failed to fetch TG post {$postId} from channel: {$channel}");
        }

        return new PostResponse(...$response->json());
    }
}
