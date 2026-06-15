<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\TelegramParsingDone;
use App\Support\MercurePublisher;

class SendTelegramParsingDoneNotification
{
    public function __construct(
        private readonly MercurePublisher $publisher
    ) {}

    public function handle(TelegramParsingDone $event): void
    {
        $draft = $event->draft;
        $topic = "user.{$draft->user_id}.source-drafts";

        $data = json_encode([
            'event' => 'parsing_done',
            'draft_id' => $draft->id,
            'total_posts' => $event->totalPosts,
            'total_links' => $event->totalLinks,
        ], JSON_THROW_ON_ERROR);

        $this->publisher->publish($topic, $data);
    }
}
