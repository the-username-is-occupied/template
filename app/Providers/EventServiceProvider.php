<?php

declare(strict_types=1);

namespace App\Providers;

use App\Events\ExtractionCompleted;
use App\Events\ExtractionDone;
use App\Events\SourceDraftError;
use App\Events\SourceMetaLoaded;
use App\Events\TelegramLinksDiscovered;
use App\Events\TelegramParsingDone;
use App\Events\TelegramParsingProgress;
use App\Events\YoutubeVideosLoaded;
use App\Listeners\SendExtractionCompletedNotification;
use App\Listeners\SendExtractionDoneNotification;
use App\Listeners\SendSourceDraftErrorNotification;
use App\Listeners\SendSourceMetaLoadedNotification;
use App\Listeners\SendTelegramLinksDiscoveredNotification;
use App\Listeners\SendTelegramParsingDoneNotification;
use App\Listeners\SendTelegramParsingProgressNotification;
use App\Listeners\SendYoutubeVideosLoadedNotification;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;

class EventServiceProvider extends ServiceProvider
{
    protected $listen = [
        SourceMetaLoaded::class => [
            SendSourceMetaLoadedNotification::class,
        ],
        SourceDraftError::class => [
            SendSourceDraftErrorNotification::class,
        ],
        ExtractionCompleted::class => [
            SendExtractionCompletedNotification::class,
        ],
        ExtractionDone::class => [
            SendExtractionDoneNotification::class,
        ],
        YoutubeVideosLoaded::class => [
            SendYoutubeVideosLoadedNotification::class,
        ],
        TelegramParsingProgress::class => [
            SendTelegramParsingProgressNotification::class,
        ],
        TelegramLinksDiscovered::class => [
            SendTelegramLinksDiscoveredNotification::class,
        ],
        TelegramParsingDone::class => [
            SendTelegramParsingDoneNotification::class,
        ],
    ];
}
