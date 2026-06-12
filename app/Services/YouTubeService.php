<?php

namespace App\Services;

use App\Domain\YouTube\DTOs\ChannelInfoData;
use App\Domain\YouTube\DTOs\ChannelUrlMappingData;
use App\Domain\YouTube\DTOs\VideoUrlsData;
use Google\Client;
use Google\Service\YouTube;
use Illuminate\Support\Facades\Log;
use Spatie\LaravelData\DataCollection;

class YouTubeService
{
    protected $youtube;

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
    public function getChannelInfo(string $identifier): ?ChannelInfoData
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
                return null;
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
        } catch (\Exception $e) {
            Log::error("YouTube API Error in getChannelInfo for '{$identifier}': ".$e->getMessage());

            return null;
        }
    }

    /**
     * 2. Получить URLs видео по каналу или плейлисту с фильтрацией по типам.
     *
     * * @param string $id ID канала (UC...) или ID плейлиста (PL...)
     * @param  array  $types  Массив из возможных значений: 'video', 'shorts', 'streams'
     * @return array Массив отсортированных URL-адресов контента
     */
    public function getVideoUrls(string $id, array $types = ['video', 'shorts', 'streams']): VideoUrlsData
    {
        if (empty($types)) {
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
                $allUrls = array_merge($allUrls, $this->getUrlsFromPlaylist($playlistId, $type));
            }

            return new VideoUrlsData(urls: array_values(array_unique($allUrls)));
        }

        // Если передан ID обычного ПЛЕЙЛИСТА (смешанный контент)
        return new VideoUrlsData(urls: $this->getUrlsFromCustomPlaylist($id, $types));
    }

    /**
     * Вспомогательный метод для сбора URL из конкретного системного плейлиста канала (высокая скорость).
     */
    protected function getUrlsFromPlaylist(string $playlistId, string $type): array
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
            } catch (\Exception $e) {
                Log::error("YouTube API Error fetching playlist {$playlistId}: ".$e->getMessage());
                break;
            }
        } while ($pageToken);

        return $urls;
    }

    /**
     * Вспомогательный метод для разбора стороннего плейлиста с детальной фильтрацией (требует больше квот).
     */
    protected function getUrlsFromCustomPlaylist(string $playlistId, array $types): array
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
            } catch (\Exception $e) {
                Log::error("YouTube API Error fetching custom playlist {$playlistId}: ".$e->getMessage());
                break;
            }
        } while ($pageToken);

        if (empty($videoIds)) {
            return [];
        }

        // Если выбраны все типы, поштучная проверка контента не нужна
        sort($types);
        $allPossibleTypes = ['video', 'shorts', 'streams'];
        sort($allPossibleTypes);
        if ($types === $allPossibleTypes) {
            return array_map(fn ($id) => "https://www.youtube.com/watch?v={$id}", $videoIds);
        }

        // Фильтруем метаданные видео пачками по 50 штук
        $filteredUrls = [];
        $chunks = array_chunk($videoIds, 50);

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
            } catch (\Exception $e) {
                Log::error('YouTube API Error filtering videos for custom playlist: '.$e->getMessage());
            }
        }

        return $filteredUrls;
    }

    /**
     * Валидация длительности формата ISO 8601 для вычисления Shorts (длительность <= 60 сек).
     */
    protected function isShortDuration(string $duration): bool
    {
        try {
            $interval = new \DateInterval($duration);
            $seconds = ($interval->h * 3600) + ($interval->i * 60) + $interval->s;

            return $interval->y === 0 && $interval->m === 0 && $interval->d === 0 && $seconds <= 60;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * 3. Resolve каналов по URL видео (Пакетный режим).
     * Принимает пачку URL-адресов видео (разных типов), возвращает маппинг: [url_видео => @channel_name]
     *
     * * @param array $urls Массив ссылок на видео (watch, shorts, live, youtu.be)
     */
    public function resolveChannelUrls(array $urls): DataCollection
    {
        $mappings = [];
        $urlToVideoId = [];
        $videoIds = [];

        // 1. Извлекаем ID видео из различных форматов URL
        foreach ($urls as $url) {
            $trimmedUrl = trim($url);
            $mappings[] = new ChannelUrlMappingData(url: $url, handle: null);

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

        if (empty($videoIds)) {
            return ChannelUrlMappingData::collection($mappings);
        }

        // 2. Получаем Channel ID для каждого Video ID (пачками по 50 штук)
        $videoChunks = array_chunk(array_unique($videoIds), 50);
        $videoIdToChannelId = [];
        $channelIds = [];

        foreach ($videoChunks as $chunk) {
            try {
                $idsString = implode(',', $chunk);
                $response = $this->youtube->videos->listVideos('snippet', ['id' => $idsString]);

                foreach ($response->getItems() as $video) {
                    $vId = $video->getId();
                    $cId = $video->getSnippet()->getChannelId();

                    $videoIdToChannelId[$vId] = $cId;
                    $channelIds[] = $cId;
                }
            } catch (\Exception $e) {
                Log::error('YouTube API Error batch fetching video details: '.$e->getMessage());
            }
        }

        if (empty($channelIds)) {
            return ChannelUrlMappingData::collection($mappings);
        }

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
            } catch (\Exception $e) {
                Log::error('YouTube API Error batch fetching channel handles: '.$e->getMessage());
            }
        }

        // 4. Склеиваем финальный маппинг: Исходный URL -> ID Видео -> ID Канала -> Хэндл (@)
        foreach ($mappings as $mapping) {
            if (isset($urlToVideoId[$mapping->url])) {
                $vId = $urlToVideoId[$mapping->url];
                if (isset($videoIdToChannelId[$vId])) {
                    $cId = $videoIdToChannelId[$vId];
                    if (isset($channelIdToHandle[$cId])) {
                        $mapping->handle = $channelIdToHandle[$cId];
                    }
                }
            }
        }

        return ChannelUrlMappingData::collection($mappings);
    }
}
