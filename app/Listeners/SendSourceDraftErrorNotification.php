<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\SourceDraftError;
use App\Support\MercurePublisher;

class SendSourceDraftErrorNotification
{
    public function __construct(
        private readonly MercurePublisher $publisher
    ) {}

    public function handle(SourceDraftError $event): void
    {
        $draft = $event->draft;
        $topic = "user.{$draft->user_id}.source-drafts";

        $data = json_encode([
            'draft_id' => $draft->id,
            'code' => $event->code,
            'message' => $event->message,
        ], JSON_THROW_ON_ERROR);

        $this->publisher->publish($topic, $data);
    }
}
