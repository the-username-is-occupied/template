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

        $this->publisher->publish($topic, [
            'draft_id' => $draft->id,
            'code' => $event->code,
            'message' => $event->message,
        ]);
    }
}
