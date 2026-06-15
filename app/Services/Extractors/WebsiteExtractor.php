<?php

declare(strict_types=1);

namespace App\Services\Extractors;

use App\Contracts\SourceExtractorInterface;
use App\Domain\NotebookLM\NotebookLMService;
use App\Events\ExtractionDone;
use App\Models\ContentSource;
use App\Models\OriginalItem;
use App\Services\AccountService;
use Illuminate\Support\Facades\Log;

class WebsiteExtractor implements SourceExtractorInterface
{
    public function __construct(
        private readonly AccountService $accountService,
        private readonly NotebookLMService $notebookLMService,
    ) {}

    public function extract(ContentSource $source): void
    {
        $url = $source->source_url;

        if (empty($url)) {
            $source->update([
                'extraction_status' => 'error',
                'error_message' => 'No URL provided',
            ]);

            return;
        }

        $source->update(['extraction_status' => 'extracting']);

        // Get available tech notebook
        $notebook = $this->accountService->getAvailableTechNotebook('source_extractor');

        if (! $notebook) {
            $source->update([
                'extraction_status' => 'error',
                'error_message' => 'No available tech notebooks',
            ]);

            return;
        }

        // Acquire lock
        $lockKey = "website_extract_{$source->id}_".uniqid();
        if (! $this->accountService->acquireTechNotebookLock($notebook, $lockKey)) {
            $source->update([
                'extraction_status' => 'error',
                'error_message' => 'Failed to acquire notebook lock',
            ]);

            return;
        }

        $sourceId = null;

        try {
            // Add URL to notebook
            $sourceDto = $this->notebookLMService->addSourceUrl(
                $notebook->account_id,
                $notebook->notebook_id,
                $url
            );
            $sourceId = $sourceDto->id;

            // Wait for indexing
            $indexedSource = $this->notebookLMService->waitUntilReady(
                $notebook->account_id,
                $notebook->notebook_id,
                $sourceId
            );

            // Extract text
            $fulltextDto = $this->notebookLMService->getSourceFulltext(
                $notebook->account_id,
                $notebook->notebook_id,
                $sourceId
            );

            // Create OriginalItem
            OriginalItem::create([
                'content_source_id' => $source->id,
                'title' => $fulltextDto->title ?? $url,
                'full_text' => $fulltextDto->content,
                'source_url' => $url,
                'word_count' => str_word_count($fulltextDto->content),
                'metadata' => [
                    'source_id' => $sourceId,
                    'notebook_id' => $notebook->notebook_id,
                ],
            ]);

            // Increment sources count
            $this->accountService->incrementSourcesCount($notebook, 1);

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
        } finally {
            // Always delete source from notebook
            if ($sourceId) {
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
}
