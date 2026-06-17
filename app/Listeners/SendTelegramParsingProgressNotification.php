<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\TelegramParsingProgress;
use App\Support\MercurePublisher;

class SendTelegramParsingProgressNotification
{
    public function __construct(
        private readonly MercurePublisher $publisher
    ) {}

    public function handle(TelegramParsingProgress $event): void
    {
        $draft = $event->draft;
        $topic = "user.{$draft->user_id}.source-drafts";

        $this->publisher->publish($topic, [
            'event' => 'parsing_progress',
            'draft_id' => $draft->id,
            'posts_parsed' => $event->postsParsed,
            'links_discovered' => $event->linksDiscovered,
        ]);
    }
}
