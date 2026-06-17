<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Enums\SourceDraftStatus;
use App\Events\ExtractionCompleted;
use App\Support\MercurePublisher;

class SendExtractionCompletedNotification
{
    public function __construct(
        private readonly MercurePublisher $publisher
    ) {}

    public function handle(ExtractionCompleted $event): void
    {
        $source = $event->source;
        $draft = $source->sourceDrafts()->latest()->first();

        if (! $draft) {
            return;
        }

        $topic = "user.{$draft->user_id}.source-drafts";

        if (! $event->success) {
            $this->publisher->publish($topic, [
                'event' => 'error',
                'draft_id' => $draft->id,
                'code' => 'extraction_failed',
                'message' => $event->errorMessage,
            ]);

            return;
        }

        $this->publisher->publish($topic, [
            'event' => 'extraction_done',
            'draft_id' => $draft->id,
            'content_source_id' => $source->id,
            'items_count' => $source->originalItems()->count(),
        ]);

        $draft->update(['status' => SourceDraftStatus::Done]);

        if ($draft->content_source_id) {
            $draft->delete();
        }
    }
}
