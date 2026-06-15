<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\TelegramLinksDiscovered;
use App\Support\MercurePublisher;

class SendTelegramLinksDiscoveredNotification
{
    public function __construct(
        private readonly MercurePublisher $publisher
    ) {}

    public function handle(TelegramLinksDiscovered $event): void
    {
        $draft = $event->draft;
        $topic = "user.{$draft->user_id}.source-drafts";

        $data = json_encode([
            'event' => 'links_batch',
            'draft_id' => $draft->id,
            'links' => $event->links,
        ], JSON_THROW_ON_ERROR);

        $this->publisher->publish($topic, $data);
    }
}
