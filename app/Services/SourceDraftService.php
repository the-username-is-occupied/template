<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\Telegram\TGScraperService;
use App\Enums\SourceDraftStatus;
use App\Enums\SourceType;
use App\Events\SourceDraftError;
use App\Events\SourceMetaLoaded;
use App\Events\YoutubeVideosLoaded;
use App\Jobs\FetchSourceMetaJob;
use App\Models\Notebook;
use App\Models\SourceDraft;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;

class SourceDraftService
{
    public function __construct(
        private readonly SmartUrlDetector $urlDetector,
        private readonly TGScraperService $tgScraperService,
        private readonly YouTubeService $youTubeService,
    ) {}

    /**
     * Create one or more source drafts from raw input.
     *
     * @return Collection<int, SourceDraft>
     */
    public function create(User $user, Notebook $notebook, string $rawInput): Collection
    {
        $urls = $this->urlDetector->parseRawInput($rawInput);
        $drafts = new Collection;

        foreach ($urls as $url) {
            $typeData = $this->urlDetector->detect($url);

            $draft = SourceDraft::create([
                'user_id' => $user->id,
                'knowledge_base_id' => $notebook->id,
                'type' => $typeData->type,
                'raw_input' => $url,
                'status' => SourceDraftStatus::FetchingMeta,
                'channel_meta' => null,
                'scrape_config' => null,
                'auto_update' => false,
            ]);

            $drafts->push($draft);

            // Dispatch job to fetch meta in background
            Bus::dispatch(new FetchSourceMetaJob($draft->id));
        }

        return $drafts;
    }

    /**
     * Abandon a source draft.
     */
    public function abandon(SourceDraft $draft): void
    {
        $draft->update(['status' => SourceDraftStatus::Abandoned]);
    }

    /**
     * Handle successful meta loading.
     */
    public function handleMetaLoaded(SourceDraft $draft, array $channelMeta): void
    {
        $draft->update([
            'channel_meta' => $channelMeta,
            'status' => SourceDraftStatus::AwaitingConfirm,
        ]);

        event(new SourceMetaLoaded($draft));
    }

    /**
     * Handle meta loading error.
     */
    public function handleMetaError(SourceDraft $draft, string $code, string $message): void
    {
        $draft->update(['status' => SourceDraftStatus::Abandoned]);

        event(new SourceDraftError($draft, $code, $message));
    }

    /**
     * Fetch meta for a draft and update it.
     * Called from FetchSourceMetaJob (thin job).
     */
    public function fetchMeta(string $draftId): void
    {
        $draft = SourceDraft::find($draftId);

        if (! $draft || $draft->status !== SourceDraftStatus::FetchingMeta) {
            return;
        }

        try {
            $meta = match ($draft->type) {
                SourceType::TelegramChannel => $this->fetchTelegramMeta($draft),
                SourceType::YoutubeChannel => $this->fetchYouTubeChannelMeta($draft),
                SourceType::YoutubeVideo, SourceType::Website, SourceType::Pdf => $this->fetchGenericMeta($draft),
                default => null,
            };

            if ($meta !== null) {
                $this->handleMetaLoaded($draft, $meta);
            } else {
                $this->handleMetaError($draft, 'meta_fetch_failed', 'Failed to fetch meta for this source type.');
            }
        } catch (\Exception $e) {
            Log::error("SourceDraftService::fetchMeta failed for draft {$draftId}: ".$e->getMessage());
            $this->handleMetaError($draft, 'meta_fetch_error', $e->getMessage());
        }
    }

    private function fetchTelegramMeta(SourceDraft $draft): ?array
    {
        $channel = $this->extractTelegramChannel($draft->raw_input);
        if (! $channel) {
            return null;
        }

        try {
            $response = $this->tgScraperService->getChannelInfo($channel);

            return [
                'title' => $response->title,
                'description' => $response->description,
                'members' => $response->members,
                'avatar_url' => $response->avatar_url,
            ];
        } catch (\Exception $e) {
            return null;
        }
    }

    private function fetchYouTubeChannelMeta(SourceDraft $draft): ?array
    {
        $identifier = $draft->raw_input;
        if (preg_match('#youtube\.com/@([a-zA-Z0-9_-]+)#i', $identifier, $matches) ||
            preg_match('#youtube\.com/channel/([a-zA-Z0-9_-]+)#i', $identifier, $matches) ||
            preg_match('#youtube\.com/c/([a-zA-Z0-9_-]+)#i', $identifier, $matches)) {
            $identifier = $matches[1];
        }

        $info = $this->youTubeService->getChannelInfo($identifier);

        if (! $info) {
            return null;
        }

        return [
            'title' => $info->title,
            'description' => $info->description,
            'members' => (string) $info->subscribers_count,
            'avatar_url' => $info->avatar_url,
        ];
    }

    private function fetchGenericMeta(SourceDraft $draft): array
    {
        return [
            'title' => $draft->raw_input,
            'description' => null,
            'members' => null,
            'avatar_url' => null,
        ];
    }

    private function extractTelegramChannel(string $url): ?string
    {
        if (preg_match('#t\.me/s/([a-zA-Z0-9_]+)#i', $url, $matches) ||
            preg_match('#t\.me/([a-zA-Z0-9_]+)#i', $url, $matches)) {
            return $matches[1];
        }

        return null;
    }

    /**
     * Load video list for YouTube channel draft
     *
     * @param  array<string>  $contentTypes  Types of content to load: 'video', 'shorts', 'streams'
     */
    public function loadVideoList(SourceDraft $draft, array $contentTypes = ['video', 'shorts']): void
    {
        if ($draft->type !== SourceType::YoutubeChannel) {
            throw new \InvalidArgumentException('loadVideoList is only for YouTube channels');
        }

        try {
            // Extract channel ID from raw_input
            $channelIdentifier = $draft->raw_input;
            if (preg_match('#youtube\.com/@([a-zA-Z0-9_-]+)#i', $channelIdentifier, $matches) ||
                preg_match('#youtube\.com/channel/([a-zA-Z0-9_-]+)#i', $channelIdentifier, $matches) ||
                preg_match('#youtube\.com/c/([a-zA-Z0-9_-]+)#i', $channelIdentifier, $matches)) {
                $channelIdentifier = $matches[1];
            }

            // Get video URLs
            $videoUrlsData = $this->youTubeService->getVideoUrls($channelIdentifier, $contentTypes);

            // Save video list to draft metadata
            $draft->update([
                'channel_meta' => array_merge($draft->channel_meta ?? [], [
                    'video_urls' => $videoUrlsData->urls,
                    'total_videos' => count($videoUrlsData->urls),
                ]),
                'status' => SourceDraftStatus::AwaitingIndex,
            ]);

            // Publish SSE event
            event(new YoutubeVideosLoaded($draft, $videoUrlsData->urls));

        } catch (\Exception $e) {
            Log::error("Failed to load video list for draft {$draft->id}: ".$e->getMessage());
            $this->handleMetaError($draft, 'video_load_failed', $e->getMessage());
        }
    }
}
