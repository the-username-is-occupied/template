<?php

declare(strict_types=1);

namespace App\Services\Extractors;

use App\Contracts\SourceExtractorInterface;
use App\Domain\NotebookLM\NotebookLMService;
use App\Events\ExtractionDone;
use App\Models\ContentSource;
use App\Models\OriginalItem;
use App\Services\AccountService;
use App\Services\WordCounter;
use Illuminate\Support\Facades\Log;

class YouTubeExtractor implements SourceExtractorInterface
{
    public function __construct(
        private readonly AccountService $accountService,
        private readonly NotebookLMService $notebookLMService,
        private readonly WordCounter $wordCounter,
    ) {}

    public function extract(ContentSource $source): void
    {
        // Get video URLs from metadata
        $videoUrls = $source->metadata['channel_meta']['video_urls'] ?? [];

        if (empty($videoUrls)) {
            $source->update([
                'extraction_status' => 'error',
                'error_message' => 'No video URLs found in metadata',
            ]);

            return;
        }

        $source->update(['extraction_status' => 'extracting']);

        $unprocessedUrls = $videoUrls;
        $processedCount = 0;

        try {
            while (! empty($unprocessedUrls)) {
                // Get available tech notebook
                $notebook = $this->accountService->getAvailableTechNotebook('source_extractor');

                if (! $notebook) {
                    throw new \Exception('No available tech notebooks');
                }

                // Calculate batch size
                $batchSize = min($notebook->getAvailableSlots(), count($unprocessedUrls));
                $batchUrls = array_splice($unprocessedUrls, 0, $batchSize);

                // Acquire lock
                $lockKey = "youtube_extract_{$source->id}_".uniqid();
                if (! $this->accountService->acquireTechNotebookLock($notebook, $lockKey)) {
                    Log::warning("Failed to acquire lock for notebook {$notebook->id}");

                    continue;
                }

                try {
                    // Add sources to notebook
                    $sourceIds = [];
                    foreach ($batchUrls as $url) {
                        $sourceDto = $this->notebookLMService->addSourceUrl(
                            $notebook->account_id,
                            $notebook->notebook_id,
                            $url
                        );
                        $sourceIds[] = $sourceDto->id;
                    }

                    // Wait for indexing
                    $this->notebookLMService->waitForSources(
                        $notebook->account_id,
                        $notebook->notebook_id,
                        $sourceIds
                    );

                    // Extract transcripts
                    foreach ($sourceIds as $index => $sourceId) {
                        $fulltextDto = $this->notebookLMService->getSourceFulltext(
                            $notebook->account_id,
                            $notebook->notebook_id,
                            $sourceId
                        );

                        // Create OriginalItem
                        OriginalItem::create([
                            'content_source_id' => $source->id,
                            'title' => $fulltextDto->title ?? basename($batchUrls[$index]),
                            'full_text' => $fulltextDto->content,
                            'source_url' => $batchUrls[$index],
                            'word_count' => $this->wordCounter->count($fulltextDto->content),
                            'metadata' => [
                                'source_id' => $sourceId,
                                'notebook_id' => $notebook->notebook_id,
                            ],
                        ]);

                        $processedCount++;
                    }

                    // Increment sources count
                    $this->accountService->incrementSourcesCount($notebook, count($sourceIds));

                } finally {
                    // Always delete sources from notebook
                    foreach ($sourceIds ?? [] as $sourceId) {
                        try {
                            $this->notebookLMService->deleteSource(
                                $notebook->account_id,
                                $notebook->notebook_id,
                                $sourceId
                            );
                        } catch (\Exception $e) {
                            Log::error("Failed to delete source {$sourceId}: ".$e->getMessage());
                        }
                    }

                    // Release lock
                    $this->accountService->releaseTechNotebookLock($notebook);
                }
            }

            // Mark as extracted
            $source->update(['extraction_status' => 'extracted']);

            // Publish SSE event
            event(new ExtractionDone($source));

        } catch (\Exception $e) {
            $source->update([
                'extraction_status' => 'error',
                'error_message' => $e->getMessage(),
            ]);
            throw $e;
        }
    }
}
