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
    private const MAX_NOTEBOOK_LOOKUP_ATTEMPTS = 3;

    private const NOTEBOOK_RETRY_DELAY_SECONDS = 10;

    private const MAX_LOCK_ACQUIRE_ATTEMPTS = 3;

    private const LOCK_RETRY_DELAY_SECONDS = 5;

    public function __construct(
        private readonly AccountService $accountService,
        private readonly NotebookLMService $notebookLMService,
        private readonly WordCounter $wordCounter,
    ) {}

    public function extract(ContentSource $source): void
    {
        $videoUrls = $source->metadata['channel_meta']['video_urls'] ?? [];

        if (empty($videoUrls)) {
            $source->update([
                'extraction_status' => 'error',
                'error_message' => 'No video URLs found in metadata',
            ]);

            return;
        }

        $unprocessedUrls = $this->filterAlreadyProcessedUrls($source, $videoUrls);

        if (empty($unprocessedUrls)) {
            $source->update(['extraction_status' => 'extracted']);
            event(new ExtractionDone($source));

            return;
        }

        $source->update(['extraction_status' => 'extracting']);

        try {
            $this->processUrls($source, $unprocessedUrls);

            $source->update(['extraction_status' => 'extracted']);
            event(new ExtractionDone($source));
        } catch (\Exception $e) {
            $source->update([
                'extraction_status' => 'error',
                'error_message' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Removes URLs that already have a fully extracted OriginalItem for this source,
     * so re-running extraction doesn't redo already-completed work.
     *
     * @param  array<int, string>  $videoUrls
     * @return array<int, string>
     */
    private function filterAlreadyProcessedUrls(ContentSource $source, array $videoUrls): array
    {
        $alreadyProcessedUrls = OriginalItem::query()
            ->where('content_source_id', $source->id)
            ->whereNotNull('full_text')
            ->whereIn('source_url', $videoUrls)
            ->pluck('source_url')
            ->all();

        if (empty($alreadyProcessedUrls)) {
            return $videoUrls;
        }

        return array_values(array_diff($videoUrls, $alreadyProcessedUrls));
    }

    /**
     * @param  array<int, string>  $unprocessedUrls
     */
    private function processUrls(ContentSource $source, array $unprocessedUrls): void
    {
        $lockKey = "youtube_extract_{$source->id}";
        $notebookAttemptsLeft = self::MAX_NOTEBOOK_LOOKUP_ATTEMPTS;
        $excludedNotebookIds = [];

        while (! empty($unprocessedUrls)) {
            $notebook = $this->accountService->getAvailableTechNotebook('source_extractor', $excludedNotebookIds);

            if (! $notebook) {
                if (--$notebookAttemptsLeft <= 0) {
                    throw new \Exception('No available tech notebooks after multiple attempts');
                }

                Log::warning("No available tech notebooks, waiting 10 seconds (attempts left: {$notebookAttemptsLeft})");
                sleep(self::NOTEBOOK_RETRY_DELAY_SECONDS);
                $excludedNotebookIds = []; // сбрасываем — вдруг что-то освободилось за это время

                continue;
            }

            $batchSize = min($notebook->getAvailableSlots(), count($unprocessedUrls));
            $batchUrls = array_splice($unprocessedUrls, 0, $batchSize);

            if (! $this->accountService->acquireTechNotebookLock($notebook, $lockKey)) {
                $unprocessedUrls = array_merge($batchUrls, $unprocessedUrls);

                Log::warning("Notebook {$notebook->id} is busy, trying another one");
                $excludedNotebookIds[] = $notebook->id;

                continue; // без sleep — сразу берём следующий по приоритету нотбук
            }

            $notebook->refresh();
            $excludedNotebookIds = [];

            try {
                $this->processBatch($source, $notebook, $batchUrls);
            } finally {
                $this->accountService->releaseTechNotebookLock($notebook);
            }
        }
    }

    /**
     * @param  array<int, string>  $batchUrls
     */
    private function processBatch(ContentSource $source, $notebook, array $batchUrls): void
    {
        $urlBySourceId = $this->addSourcesToNotebook($notebook, $batchUrls);

        if (empty($urlBySourceId)) {
            return;
        }

        $sourceIds = array_keys($urlBySourceId);

        try {
            $this->notebookLMService->waitForSources(
                $notebook->account_id,
                $notebook->notebook_id,
                $sourceIds
            );

            $this->extractTranscripts($source, $notebook, $urlBySourceId);
        } catch (\Throwable $e) {
            Log::error($e->getMessage());
        } finally {
            $this->cleanupNotebookSources($notebook, $sourceIds);
        }
    }

    /**
     * Adds each URL as a source in the notebook individually, so that a single bad
     * URL doesn't abort the whole batch. Failing URLs are logged and skipped.
     *
     * @param  array<int, string>  $batchUrls
     * @return array<int|string, string> map of sourceId => url, for successfully added sources
     */
    private function addSourcesToNotebook($notebook, array $batchUrls): array
    {
        $urlBySourceId = [];

        foreach ($batchUrls as $url) {
            try {
                $sourceDto = $this->notebookLMService->addSourceUrl(
                    $notebook->account_id,
                    $notebook->notebook_id,
                    $url
                );

                $urlBySourceId[$sourceDto->id] = $url;
                $this->accountService->incrementSourcesCount($notebook, 1);
            } catch (\Throwable $e) {
                Log::error("Failed to add source URL {$url}: ".$e->getMessage());

                continue;
            }
        }

        return $urlBySourceId;
    }

    /**
     * @param  array<int|string, string>  $urlBySourceId  map of sourceId => url
     */
    private function extractTranscripts(ContentSource $source, $notebook, array $urlBySourceId): void
    {
        foreach ($urlBySourceId as $sourceId => $url) {
            $fulltextDto = $this->notebookLMService->getSourceFulltext(
                $notebook->account_id,
                $notebook->notebook_id,
                $sourceId
            );

            OriginalItem::create([
                'content_source_id' => $source->id,
                'title' => $fulltextDto->title ?? basename($url),
                'full_text' => $fulltextDto->content,
                'source_url' => $url,
                'word_count' => $this->wordCounter->count($fulltextDto->content),
                'metadata' => [
                    'source_id' => $sourceId,
                    'notebook_id' => $notebook->notebook_id,
                ],
            ]);
        }
    }

    /**
     * @param  array<int, int|string>  $sourceIds
     */
    private function cleanupNotebookSources($notebook, array $sourceIds): void
    {
        foreach ($sourceIds as $sourceId) {
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

        if (! empty($sourceIds)) {
            $this->accountService->decrementSourcesCount($notebook, count($sourceIds));
        }
    }
}
