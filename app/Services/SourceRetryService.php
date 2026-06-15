<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\ExtractionStatus;
use App\Jobs\ProcessSourceJob;
use App\Models\ContentSource;
use Illuminate\Support\Facades\Log;

class SourceRetryService
{
    /**
     * Retry content sources stuck in uploading status for more than the specified minutes.
     *
     * @param  int  $staleThresholdMinutes  Number of minutes to consider a source stale
     * @return int Number of sources reset and dispatched
     */
    public function retryStaleUploads(int $staleThresholdMinutes = 30): int
    {
        $staleThreshold = now()->subMinutes($staleThresholdMinutes);

        $staleSources = ContentSource::where('extraction_status', ExtractionStatus::Uploading)
            ->where('updated_at', '<', $staleThreshold)
            ->get();

        foreach ($staleSources as $source) {
            $this->resetAndDispatch($source);
        }

        return $staleSources->count();
    }

    /**
     * Reset a source's status to pending and dispatch a retry job.
     */
    private function resetAndDispatch(ContentSource $source): void
    {
        $source->update([
            'extraction_status' => ExtractionStatus::Pending,
            'error_message' => 'Reset from stale uploading status',
            'error_code' => 'stale_reset',
        ]);

        ProcessSourceJob::dispatch($source->id);

        Log::info('Reset stale uploading source and dispatched retry', [
            'content_source_id' => $source->id,
            'url' => $source->url,
        ]);
    }
}
