<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\YouTube\DTOs\ChannelInfoData;
use App\Domain\YouTube\DTOs\ChannelUrlMappingData;
use App\Domain\YouTube\DTOs\VideoUrlsData;
use App\Exceptions\YouTubeApiException;
use DateInterval;
use Exception;
use Google\Client;
use Google\Service\YouTube;
use Illuminate\Support\Facades\Log;
use Spatie\LaravelData\DataCollection;

class YouTubeService
{
    protected YouTube $youtube;

    public function __construct()
    {
        $client = new Client;
        $client->setDeveloperKey(config('services.youtube.key'));
        $this->youtube = new YouTube($client);
    }

    /**
     * 1. Получить базовые сведения о канале.
     *
     * * @param string $identifier ID канала (UC...) или хэндл (например, @GoogleDevelopers)
     */
    public function getChannelInfo(string $identifier): ChannelInfoData
    {
        $params = [];

        if (str_starts_with($identifier, 'UC')) {
            $params['id'] = $identifier;
        } else {
            $params['forHandle'] = str_starts_with($identifier, '@') ? $identifier : '@'.$identifier;
        }

        try {
            $response = $this->youtube->channels->listChannels('snippet,statistics', $params);
            $items = $response->getItems();

            if (empty($items)) {
                throw new YouTubeApiException("YouTube channel not found: {$identifier}");
            }

            $channel = $items[0];
            $snippet = $channel->getSnippet();
            $statistics = $channel->getStatistics();

            return new ChannelInfoData(
                id: $channel->getId(),
                title: $snippet->getTitle(),
                description: $snippet->getDescription(),
                handle: $snippet->getCustomUrl(),
                avatar_url: $snippet->getThumbnails()->getHigh()?->getUrl() ?? $snippet->getThumbnails()->getDefault()?->getUrl(),
                published_at: $snippet->getPublishedAt(),
                subscribers_count: (int) $statistics->getSubscriberCount(),
                view_count: (int) $statistics->getViewCount(),
                video_count: (int) $statistics->getVideoCount(),
            );
        } catch (YouTubeApiException $e) {
            throw $e;
        } catch (Exception $e) {
            throw new YouTubeApiException("YouTube API Error in getChannelInfo for '{$identifier}': ".$e->getMessage(), 0, $e);
        }
    }

    /**
     * 2. Получить URLs видео по каналу или плейлисту с фильтрацией по типам.
     *
     * @param  string  $id  ID канала (UC...) или ID плейлиста (PL...)
     * @param  array  $types  Массив из возможных значений: 'video', 'shorts', 'streams'
     * @param  int|null  $limit  Ограничение количества результатов (null = без ограничений)
     * @param  string|null  $sort  Сортировка: null (новые первые) или 'oldest' (старые первые)
     */
    public function getVideoUrls(string $id, array $types = ['video', 'shorts', 'streams'], ?int $limit = null, ?string $sort = null): VideoUrlsData
    {
        if ($types === []) {
            return new VideoUrlsData(urls: []);
        }

        // Оптимизация для КАНАЛА: YouTube генерирует скрытые системные плейлисты для разных типов контента
        if (str_starts_with($id, 'UC')) {
            $channelSuffix = substr($id, 2);
            $playlistsToFetch = [];

            if (in_array('video', $types)) {
                $playlistsToFetch['video'] = 'UULF'.$channelSuffix; // Только стандартные видео
            }
            if (in_array('shorts', $types)) {
                $playlistsToFetch['shorts'] = 'UUSH'.$channelSuffix; // Только Shorts
            }
            if (in_array('streams', $types)) {
                $playlistsToFetch['streams'] = 'UULV'.$channelSuffix; // Только трансляции / стримы
            }

            $allUrls = [];
            foreach ($playlistsToFetch as $type => $playlistId) {
                try {
                    $urls = $this->getUrlsFromPlaylist($playlistId, $type, $limit);
                    $allUrls = array_merge($allUrls, $urls);
                } catch (Exception $e) {
                    Log::error("Failed to fetch {$type} playlist {$playlistId}: ".$e->getMessage());
                }
            }

            $urls = array_values(array_unique($allUrls));
        } else {
            // Если передан ID обычного ПЛЕЙЛИСТА (смешанный контент)
            $urls = $this->getUrlsFromCustomPlaylist($id, $types, $limit);
        }

        // Применяем сортировку
        if ($sort === 'oldest') {
            $urls = array_reverse($urls); // Старые первыми
        }
        // 'newest' - порядок по умолчанию (YouTube уже возвращает новые первыми)

        // Применяем лимит
        if ($limit !== null && $limit > 0) {
            $urls = array_slice($urls, 0, $limit);
        }

        return new VideoUrlsData(urls: $urls);
    }

    /**
     * Вспомогательный метод для сбора URL из конкретного системного плейлиста канала (высокая скорость).
     *
     * @param  int|null  $limit  Ограничение количества результатов (null = без ограничений)
     */
    protected function getUrlsFromPlaylist(string $playlistId, string $type, ?int $limit = null): array
    {
        $urls = [];
        $pageToken = null;

        do {
            $params = ['playlistId' => $playlistId, 'maxResults' => 50];
            if ($pageToken) {
                $params['pageToken'] = $pageToken;
            }

            try {
                $response = $this->youtube->playlistItems->listPlaylistItems('contentDetails', $params);
                foreach ($response->getItems() as $item) {
                    $videoId = $item->getContentDetails()->getVideoId();

                    // Форматируем URL в зависимости от типа
                    if ($type === 'shorts') {
                        $urls[] = "https://www.youtube.com/shorts/{$videoId}";
                    } else {
                        $urls[] = "https://www.youtube.com/watch?v={$videoId}";
                    }
                }
                $pageToken = $response->getNextPageToken();
            } catch (Exception $e) {
                throw new YouTubeApiException("YouTube API Error fetching playlist {$playlistId}: ".$e->getMessage(), 0, $e);
            }

            // Оптимизация: ранний выход при достижении лимита
            if ($limit !== null && count($urls) >= $limit) {
                $urls = array_slice($urls, 0, $limit);
                break;
            }
        } while ($pageToken);

        return $urls;
    }

    /**
     * Вспомогательный метод для разбора стороннего плейлиста с детальной фильтрацией (требует больше квот).
     *
     * @param  int|null  $limit  Ограничение количества результатов (null = без ограничений)
     */
    protected function getUrlsFromCustomPlaylist(string $playlistId, array $types, ?int $limit = null): array
    {
        $videoIds = [];
        $pageToken = null;

        do {
            $params = ['playlistId' => $playlistId, 'maxResults' => 50];
            if ($pageToken) {
                $params['pageToken'] = $pageToken;
            }

            try {
                $response = $this->youtube->playlistItems->listPlaylistItems('contentDetails', $params);
                foreach ($response->getItems() as $item) {
                    $videoIds[] = $item->getContentDetails()->getVideoId();
                }
                $pageToken = $response->getNextPageToken();
            } catch (Exception $e) {
                throw new YouTubeApiException("YouTube API Error fetching custom playlist {$playlistId}: ".$e->getMessage(), 0, $e);
            }
        } while ($pageToken);

        if ($videoIds === []) {
            return [];
        }

        // Если выбраны все типы, поштучная проверка контента не нужна
        sort($types);
        $allPossibleTypes = ['video', 'shorts', 'streams'];
        sort($allPossibleTypes);
        if ($types === $allPossibleTypes) {
            return array_map(fn ($id): string => "https://www.youtube.com/watch?v={$id}", $videoIds);
        }

        // Фильтруем метаданные видео пачками по 50 штук
        $filteredUrls = [];
        $chunks = array_chunk($videoIds, 50);
        $errors = [];

        foreach ($chunks as $chunk) {
            try {
                $idsString = implode(',', $chunk);
                $response = $this->youtube->videos->listVideos('snippet,contentDetails', ['id' => $idsString]);

                foreach ($response->getItems() as $video) {
                    $videoId = $video->getId();
                    $duration = $video->getContentDetails()->getDuration();
                    $liveBroadcast = $video->getSnippet()->getLiveBroadcastContent();

                    $isStream = in_array($liveBroadcast, ['live', 'upcoming']);
                    $isShort = false;

                    if (! $isStream && $duration) {
                        $isShort = $this->isShortDuration($duration);
                    }

                    $isRegularVideo = ! $isStream && ! $isShort;

                    if ($isStream && in_array('streams', $types)) {
                        $filteredUrls[] = "https://www.youtube.com/watch?v={$videoId}";
                    } elseif ($isShort && in_array('shorts', $types)) {
                        $filteredUrls[] = "https://www.youtube.com/shorts/{$videoId}";
                    } elseif ($isRegularVideo && in_array('video', $types)) {
                        $filteredUrls[] = "https://www.youtube.com/watch?v={$videoId}";
                    }
                }
            } catch (Exception $e) {
                $errors[] = 'Failed to filter video chunk: '.$e->getMessage();
            }
        }

        if ($errors !== []) {
            throw new YouTubeApiException("YouTube API Error filtering videos for custom playlist {$playlistId}: ".implode('; ', $errors));
        }

        return $filteredUrls;
    }

    /**
     * Валидация длительности формата ISO 8601 для вычисления Shorts (длительность <= 60 сек).
     */
    protected function isShortDuration(string $duration): bool
    {
        try {
            $interval = new DateInterval($duration);
            $seconds = ($interval->h * 3600) + ($interval->i * 60) + $interval->s;

            return $interval->y === 0 && $interval->m === 0 && $interval->d === 0 && $seconds <= 60;
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * 3. Resolve каналов и метаданных по URL видео (Пакетный режим).
     * Принимает пачку URL-адресов видео (разных типов), возвращает маппинг:
     * [url_видео => {handle, title, description, tags, channelId, channelTitle,
     *                publishedAt, duration, viewCount, likeCount, commentCount}]
     *
     * @param  array  $urls  Массив ссылок на видео (watch, shorts, live, youtu.be)
     */
    public function resolveChannelUrls(array $urls, bool $resolveChannel = false): DataCollection
    {
        $mappings = [];
        $urlToVideoId = [];
        $videoIds = [];

        // 1. Извлекаем ID видео из различных форматов URL
        foreach ($urls as $url) {
            $trimmedUrl = trim($url);
            $mappings[] = new ChannelUrlMappingData(url: $url);

            $videoId = null;
            if (preg_match('/v=([a-zA-Z0-9_\-]{11})/', $trimmedUrl, $matches)) {
                $videoId = $matches[1];
            } elseif (preg_match('/(?:shorts|live)\/([a-zA-Z0-9_\-]{11})/i', $trimmedUrl, $matches)) {
                $videoId = $matches[1];
            } elseif (preg_match('/youtu\.be\/([a-zA-Z0-9_\-]{11})/i', $trimmedUrl, $matches)) {
                $videoId = $matches[1];
            }

            if ($videoId) {
                $urlToVideoId[$url] = $videoId;
                $videoIds[] = $videoId;
            }
        }

        if ($videoIds === []) {
            return ChannelUrlMappingData::collect($mappings, DataCollection::class);
        }

        // 2. Получаем данные видео (snippet, contentDetails, statistics) пачками по 50 штук
        $videoChunks = array_chunk(array_unique($videoIds), 50);
        $videoIdToData = [];
        $channelIds = [];

        foreach ($videoChunks as $chunk) {
            try {
                $idsString = implode(',', $chunk);
                $response = $this->youtube->videos->listVideos(
                    'snippet,contentDetails,statistics',
                    ['id' => $idsString]
                );

                foreach ($response->getItems() as $video) {
                    $vId = $video->getId();
                    $snippet = $video->getSnippet();
                    $contentDetails = $video->getContentDetails();
                    $statistics = $video->getStatistics();

                    $cId = $snippet->getChannelId();
                    $channelIds[] = $cId;

                    $videoIdToData[$vId] = [
                        'title' => $snippet->getTitle(),
                        'description' => $snippet->getDescription(),
                        // 'tags' => $snippet->getTags() ?: null,
                        'channelId' => $cId,
                        'channelTitle' => $snippet->getChannelTitle(),
                        'publishedAt' => $snippet->getPublishedAt(),
                        'duration' => $contentDetails?->getDuration(),
                        'viewCount' => $statistics && $statistics->getViewCount() !== null
                            ? (int) $statistics->getViewCount() : null,
                        'likeCount' => $statistics && $statistics->getLikeCount() !== null
                            ? (int) $statistics->getLikeCount() : null,
                        'commentCount' => $statistics && $statistics->getCommentCount() !== null
                            ? (int) $statistics->getCommentCount() : null,
                    ];
                }
            } catch (Exception $e) {
                throw new YouTubeApiException('YouTube API Error batch fetching video details: '.$e->getMessage(), 0, $e);
            }
        }

        if ($channelIds === []) {
            throw new YouTubeApiException('No channel IDs found for the provided video URLs');
        }

        if ($resolveChannel) {

            // 3. Получаем Handle (@channel_name) для каждого уникального Channel ID (пачками по 50 штук)
            $channelChunks = array_chunk(array_unique($channelIds), 50);
            $channelIdToHandle = [];

            foreach ($channelChunks as $chunk) {
                try {
                    $idsString = implode(',', $chunk);
                    $response = $this->youtube->channels->listChannels('snippet', ['id' => $idsString]);

                    foreach ($response->getItems() as $channel) {
                        $cId = $channel->getId();
                        $customUrl = $channel->getSnippet()->getCustomUrl();

                        if ($customUrl) {
                            $channelIdToHandle[$cId] = str_starts_with($customUrl, '@') ? $customUrl : '@'.$customUrl;
                        } else {
                            $title = $channel->getSnippet()->getTitle();
                            $channelIdToHandle[$cId] = '@'.preg_replace('/[^a-zA-Z0-9]/', '', $title);
                        }
                    }
                } catch (Exception $e) {
                    throw new YouTubeApiException('YouTube API Error batch fetching channel handles: '.$e->getMessage(), 0, $e);
                }
            }
        }

        // 4. Склеиваем финальный маппинг: Исходный URL -> ID Видео -> данные видео + Хэндл (@)
        foreach ($mappings as $mapping) {
            if (! isset($urlToVideoId[$mapping->url])) {
                continue;
            }

            $vId = $urlToVideoId[$mapping->url];
            $mapping->videoId = $vId;

            if (! isset($videoIdToData[$vId])) {
                continue;
            }

            $data = $videoIdToData[$vId];

            $mapping->title = $data['title'];
            $mapping->description = $data['description'];
            $mapping->tags = $data['tags'] ?? null;
            $mapping->channelId = $data['channelId'];
            $mapping->channelTitle = $data['channelTitle'];
            $mapping->publishedAt = $data['publishedAt'];
            $mapping->duration = $data['duration'];
            $mapping->viewCount = $data['viewCount'];
            $mapping->likeCount = $data['likeCount'];
            $mapping->commentCount = $data['commentCount'];

            if (isset($channelIdToHandle[$data['channelId']])) {
                $mapping->handle = $channelIdToHandle[$data['channelId']];
            }
        }

        return ChannelUrlMappingData::collect($mappings, DataCollection::class);
    }
}
