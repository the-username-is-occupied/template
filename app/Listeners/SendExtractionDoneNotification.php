<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\ExtractionDone;
use App\Support\MercurePublisher;

class SendExtractionDoneNotification
{
    public function __construct(
        private readonly MercurePublisher $publisher,
    ) {}

    public function handle(ExtractionDone $event): void
    {
        $source = $event->source;

        $topic = "user.{$source->user_id}.source-drafts";

        $this->publisher->publish(
            $topic,
            [
                'event' => 'extraction_done',
                'data' => [
                    'source_id' => $source->id,
                    'status' => $source->extraction_status->value,
                ],
            ]
        );
    }
}
