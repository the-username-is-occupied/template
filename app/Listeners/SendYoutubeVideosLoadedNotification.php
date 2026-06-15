<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\YoutubeVideosLoaded;
use App\Support\MercurePublisher;

class SendYoutubeVideosLoadedNotification
{
    public function __construct(
        private readonly MercurePublisher $publisher,
    ) {}

    public function handle(YoutubeVideosLoaded $event): void
    {
        $draft = $event->draft;
        $videos = $event->videos;

        $this->publisher->publish(
            "source-drafts/{$draft->id}",
            json_encode([
                'event' => 'videos_loaded',
                'data' => [
                    'draft_id' => $draft->id,
                    'videos' => $videos,
                ],
            ])
        );
    }
}
