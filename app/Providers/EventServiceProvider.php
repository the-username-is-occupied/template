<?php

declare(strict_types=1);

namespace App\Providers;

use App\Events\ExtractionCompleted;
use App\Events\SourceDraftError;
use App\Events\SourceMetaLoaded;
use App\Listeners\SendExtractionCompletedNotification;
use App\Listeners\SendSourceDraftErrorNotification;
use App\Listeners\SendSourceMetaLoadedNotification;
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
    ];
}
