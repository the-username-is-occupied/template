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
use Illuminate\Support\Facades\Storage;

class NlmFileExtractor implements SourceExtractorInterface
{
    public function __construct(
        private readonly AccountService $accountService,
        private readonly NotebookLMService $notebookLMService,
    ) {}

    public function extract(ContentSource $source): void
    {
        $filePath = $source->file_ref;

        if (empty($filePath)) {
            $source->update([
                'extraction_status' => 'error',
                'error_message' => 'No file path provided',
            ]);

            return;
        }

        // Check if file exists
        if (! Storage::exists($filePath)) {
            $source->update([
                'extraction_status' => 'error',
                'error_message' => 'File not found: '.$filePath,
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
        $lockKey = "file_extract_{$source->id}_".uniqid();
        if (! $this->accountService->acquireTechNotebookLock($notebook, $lockKey)) {
            $source->update([
                'extraction_status' => 'error',
                'error_message' => 'Failed to acquire notebook lock',
            ]);

            return;
        }

        $sourceId = null;
        $fullPath = Storage::path($filePath);

        try {
            // Add file to notebook
            $sourceDto = $this->notebookLMService->addSourceFile(
                $notebook->account_id,
                $notebook->notebook_id,
                $fullPath
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
                'title' => $fulltextDto->title ?? basename($filePath),
                'full_text' => $fulltextDto->content,
                'source_url' => null, // File, not URL
                'word_count' => str_word_count($fulltextDto->content),
                'metadata' => [
                    'source_id' => $sourceId,
                    'notebook_id' => $notebook->notebook_id,
                    'file_path' => $filePath,
                    'mime_type' => $indexedSource->mime_type ?? null,
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
