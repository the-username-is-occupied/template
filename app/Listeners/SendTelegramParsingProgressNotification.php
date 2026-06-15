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

        $data = json_encode([
            'event' => 'parsing_progress',
            'draft_id' => $draft->id,
            'posts_parsed' => $event->postsParsed,
            'links_discovered' => $event->linksDiscovered,
        ], JSON_THROW_ON_ERROR);

        $this->publisher->publish($topic, $data);
    }
}
