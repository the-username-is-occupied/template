<?php

declare(strict_types=1);

namespace App\Services\Extractors;

use App\Contracts\SourceExtractorInterface;
use App\Domain\NotebookLM\DTOs\SourceDTO;
use App\Domain\NotebookLM\DTOs\SourceFulltextDTO;
use App\Domain\NotebookLM\NotebookLMService;
use App\Events\ExtractionDone;
use App\Models\ContentSource;
use App\Models\Notebook;
use App\Models\OriginalItem;
use App\Models\TechNotebook;
use App\Services\AccountService;
use App\Services\WordCounter;
use App\Services\YouTubeOriginalItemMetadataResolver;
use Illuminate\Support\Facades\Log;
use Throwable;

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
        private readonly YouTubeOriginalItemMetadataResolver $metadataResolver,
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
     * so re-running extraction doesn't asd  redo already-completed work.
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
    private function processBatch(ContentSource $source, TechNotebook $notebook, array $batchUrls): void
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
        } catch (Throwable $e) {
            Log::error($e->getMessage());
        } finally {
            $this->cleanupNotebookSources($notebook, $sourceIds);
        }
    }

    /**
     * Adds all URLs in the batch to the notebook in parallel (via Http::pool under the
     * hood), so a slow or bad URL no longer serializes the whole batch. Failing URLs
     * are logged and skipped, same as before.
     *
     * @param  array<int, string>  $batchUrls
     * @return array<int|string, string> map of sourceId => url, for successfully added sources
     */
    private function addSourcesToNotebook(TechNotebook $notebook, array $batchUrls): array
    {
        $poolResults = $this->notebookLMService->addSourceUrlsPool(
            $notebook->account_id,
            $notebook->notebook_id,
            $batchUrls
        );

        $urlBySourceId = [];
        $successCount = 0;

        foreach (array_values($batchUrls) as $index => $url) {
            $result = $poolResults[$index] ?? null;

            if (! $result instanceof SourceDTO) {
                $message = $result instanceof Throwable ? $result->getMessage() : 'Unknown error';
                Log::error("Failed to add source URL {$url}: {$message}");

                continue;
            }

            $urlBySourceId[$result->id] = $url;
            $successCount++;
        }

        if ($successCount > 0) {
            $this->accountService->incrementSourcesCount($notebook, $successCount);
        }

        return $urlBySourceId;
    }

    /**
     * Fetches full text for all sources in parallel (via Http::pool under the hood).
     * A failure on one source no longer blocks the rest from being extracted.
     *
     * @param  array<int|string, string>  $urlBySourceId  map of sourceId => url
     */
    private function extractTranscripts(ContentSource $source, TechNotebook $notebook, array $urlBySourceId): void
    {
        $sourceIds = array_keys($urlBySourceId);

        $poolResults = $this->notebookLMService->getSourceFulltextsPool(
            $notebook->account_id,
            $notebook->notebook_id,
            $sourceIds
        );

        $createdItems = [];

        foreach ($urlBySourceId as $sourceId => $url) {
            $result = $poolResults[$sourceId] ?? null;

            if (! $result instanceof SourceFulltextDTO) {
                $message = $result instanceof Throwable ? $result->getMessage() : 'Unknown error';
                Log::error("Failed to fetch fulltext for source {$sourceId} ({$url}): {$message}");

                continue;
            }

            $createdItems[] = OriginalItem::create([
                'content_source_id' => $source->id,
                'title' => $result->title ?? basename($url),
                'full_text' => $result->content,
                'source_url' => $url,
                'word_count' => $this->wordCounter->count($result->content),
            ]);
        }

        if (! empty($createdItems)) {
            $this->metadataResolver->resolveAndUpdate(collect($createdItems));
        }
    }

    /**
     * Deletes all sources for the batch in parallel (via Http::pool under the hood).
     *
     * @param  array<int, int|string>  $sourceIds
     */
    private function cleanupNotebookSources(TechNotebook $notebook, array $sourceIds): void
    {
        $notebook->clean();

        return;
        if (empty($sourceIds)) {
            return;
        }

        $poolResults = $this->notebookLMService->deleteSourcesPool(
            $notebook->account_id,
            $notebook->notebook_id,
            $sourceIds
        );

        foreach ($poolResults as $sourceId => $result) {
            if ($result instanceof Throwable) {
                Log::error("Failed to delete source {$sourceId}: ".$result->getMessage());
            }
        }

        $this->accountService->decrementSourcesCount($notebook, count($sourceIds));
    }
}
