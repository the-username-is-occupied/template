<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\SourceMetaLoaded;
use App\Support\MercurePublisher;

class SendSourceMetaLoadedNotification
{
    public function __construct(
        private readonly MercurePublisher $publisher
    ) {}

    public function handle(SourceMetaLoaded $event): void
    {
        $draft = $event->draft;
        $topic = "user.{$draft->user_id}.source-drafts";

        $data = json_encode([
            'draft_id' => $draft->id,
            'channel_meta' => $draft->channel_meta,
        ], JSON_THROW_ON_ERROR);

        $this->publisher->publish($topic, $data);
    }
}
