<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\SourceRetryService;
use Illuminate\Console\Command;

class RetryStaleUploadsCommand extends Command
{
    protected $signature = 'sources:retry-stale-uploads';

    protected $description = 'Retry content sources stuck in uploading status for more than 30 minutes';

    public function handle(SourceRetryService $retryService): int
    {
        $count = $retryService->retryStaleUploads();

        if ($count === 0) {
            $this->info('No stale uploads found.');
        } else {
            $this->info("Found and reset {$count} stale upload(s).");
        }

        return Command::SUCCESS;
    }
}
