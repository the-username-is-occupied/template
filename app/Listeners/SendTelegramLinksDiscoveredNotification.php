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

        $this->publisher->publish($topic, [
            'event' => 'links_batch',
            'draft_id' => $draft->id,
            'links' => $event->links,
        ]);
    }
}
