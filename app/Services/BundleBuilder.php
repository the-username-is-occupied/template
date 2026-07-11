<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\NotebookLM\NotebookLMService;
use App\Enums\MdBundleStatus;
use App\Enums\MdBundleType;
use App\Jobs\ConsolidateBundlesJob;
use App\Models\MdBundle;
use App\Models\Notebook;
use App\Models\OriginalItem;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class BundleBuilder
{
    private const ACTIVE_DELTA_MAX_WORDS = 10_000;

    private const ACTIVE_QUARTER_MAX_WORDS = 120_000;

    private const FROZEN_QUARTER_MAX_WORDS = 120_000;

    private const FROZEN_HALF_MAX_WORDS = 240_000;

    private const FROZEN_FULL_MAX_WORDS = 480_000;

    public function __construct(
        private readonly BundleRenderer2 $renderer,
        private readonly BundleItemsService $bundleItemsService,
        private readonly WordCounter $wordCounter,
        private readonly NotebookLMService $notebookLMService,
    ) {}

    /**
     * Build bundles for a notebook from unbundled original items.
     */
    public function build(Notebook $notebook): void
    {
        $contentSourceIds = $notebook->contentSources()->pluck('content_sources.id');

        Log::info('Fetching unbundled items for bundle build', [
            'notebook_id' => $notebook->id,
            'content_source_ids' => $contentSourceIds->toArray(),
        ]);

        if ($contentSourceIds->isEmpty()) {
            return;
        }

        $items = OriginalItem::whereIn('content_source_id', $contentSourceIds)
            ->unbundled()
            ->orderBy('published_at');

        if (! $items->exists()) {
            Log::info('Starting bundle build. Items to build not found', [
                'notebook_id' => $notebook->id,
                'content_source_ids' => $contentSourceIds->toArray(),
                'unbundled_item_count' => 0,
            ]);

            return;
        }

        Log::info('Starting bundle build', [
            'notebook_id' => $notebook->id,
            'content_source_ids' => $contentSourceIds->toArray(),
            'unbundled_item_count' => $items->count(),
        ]);

        $hasExistingBundles = $notebook->mdBundles()->exists();

        DB::transaction(function () use ($notebook, $items, $hasExistingBundles): void {
            if ($hasExistingBundles && $items->sum('word_count') < 8000) {
                $this->performContinuousIndexing($notebook, $items->get());
            } else {
                $this->performPrimaryIndexing($notebook, $items->get());
            }
        });

        // Wait for all uploaded sources to be ready before generating description
        $this->waitForBundleSources($notebook);

        // Set notebook description after sources are ready
        $notebook->setDescription();
    }

    /**
     * Primary indexing: pack all existing items into maximum-size bundles.
     */
    private function performPrimaryIndexing(Notebook $notebook, Collection $items): void
    {
        $accumulator = collect();
        $accWords = 0;

        foreach ($items as $item) {
            // Count words from rendered content (including metadata) to ensure accurate sizing
            $renderedItem = $this->renderer->render(collect([$item]));
            $itemWords = $this->wordCounter->count($renderedItem);

            if ($accumulator->isNotEmpty() && $accWords + $itemWords >= self::FROZEN_FULL_MAX_WORDS) {
                $this->createBundleAndUpload($notebook, $accumulator, MdBundleType::FrozenFull);
                $accumulator = collect();
                $accWords = 0;
            }

            $accumulator->push($item);
            $accWords += $itemWords;
        }

        if ($accumulator->isEmpty()) {
            return;
        }

        $this->createBundleAndUpload($notebook, $accumulator, $this->determineFinalBundleType($accWords));
    }

    /**
     * Determine the bundle type for the remaining items based on word count.
     */
    private function determineFinalBundleType(int $wordCount): MdBundleType
    {
        return match (true) {
            $wordCount >= self::FROZEN_FULL_MAX_WORDS => MdBundleType::FrozenFull,
            $wordCount >= self::FROZEN_HALF_MAX_WORDS => MdBundleType::FrozenHalf,
            $wordCount >= self::FROZEN_QUARTER_MAX_WORDS => MdBundleType::FrozenQuarter,
            $wordCount >= self::ACTIVE_QUARTER_MAX_WORDS => MdBundleType::ActiveQuarter,
            default => MdBundleType::ActiveDelta,
        };
    }

    /**
     * Continuous indexing: add items to active_delta, flush cascadingly when full.
     */
    private function performContinuousIndexing(Notebook $notebook, Collection $items): void
    {
        $delta = $notebook->mdBundles()->activeDelta()->first()
            ?? $this->createEmptyBundle($notebook, MdBundleType::ActiveDelta);

        foreach ($items as $item) {
            // Count words from rendered content (including metadata) to ensure accurate sizing
            $renderedItem = $this->renderer->render(collect([$item]));
            $itemWords = $this->wordCounter->count($renderedItem);

            if (($delta->word_count ?? 0) + $itemWords > self::ACTIVE_DELTA_MAX_WORDS) {
                $this->flushDeltaToQuarter($notebook, $delta);
                $delta = $this->createEmptyBundle($notebook, MdBundleType::ActiveDelta);
            }

            $this->appendItemToBundle($delta, $item);
        }
    }

    /**
     * Flush active_delta content into active_quarter.
     * Moves all items from delta to quarter, clears delta.
     */
    private function flushDeltaToQuarter(Notebook $notebook, MdBundle $delta): void
    {
        $deltaItems = $delta->bundleItems()->with('originalItem')->get()->pluck('originalItem');

        if ($deltaItems->isEmpty()) {
            return;
        }

        $quarter = $notebook->mdBundles()->activeQuarter()->first()
            ?? $this->createEmptyBundle($notebook, MdBundleType::ActiveQuarter);

        $deltaWordCount = $delta->word_count ?? 0;

        if (($quarter->word_count ?? 0) + $deltaWordCount > self::ACTIVE_QUARTER_MAX_WORDS) {
            $this->freezeQuarter($quarter);
            $quarter = $this->createEmptyBundle($notebook, MdBundleType::ActiveQuarter);

            $frozenQuarterCount = $notebook->mdBundles()
                ->where('type', MdBundleType::FrozenQuarter)
                ->where('is_consolidating', false)
                ->count();

            if ($frozenQuarterCount >= 2) {
                ConsolidateBundlesJob::dispatch($notebook->id, 'quarter_to_half');
            }
        }

        // Move items from delta to quarter (DB only, no NLM yet)
        $maxPosition = $quarter->bundleItems()->max('position') ?? 0;

        foreach ($deltaItems as $i => $item) {
            $delta->bundleItems()
                ->where('original_item_id', $item->id)
                ->update([
                    'bundle_id' => $quarter->id,
                    'position' => $maxPosition + $i + 1,
                ]);
        }

        // Recalculate quarter word count from rendered content
        $allQuarterItems = $quarter->bundleItems()
            ->with('originalItem')
            ->orderBy('position')
            ->get()
            ->pluck('originalItem');
        $quarterContent = $this->renderer->render($allQuarterItems);
        $quarter->update(['word_count' => $this->wordCounter->count($quarterContent)]);

        $quarter->increment('word_count', $deltaWordCount);

        // Build quarter content from scratch (all items in order)
        $allQuarterItems = $quarter->bundleItems()
            ->with('originalItem')
            ->orderBy('position')
            ->get()
            ->pluck('originalItem');

        $quarterContent = $this->renderer->render($allQuarterItems);

        // Update quarter file on disk
        $disk = Storage::disk('bundles');
        $quarterFilePath = "{$notebook->id}/{$quarter->id}.md";
        $disk->put($quarterFilePath, $quarterContent);

        // Update quarter in NLM: delete old source, upload new file
        if ($quarter->nlm_source_id) {
            $this->deleteNlmSource($notebook, $quarter->nlm_source_id);
        }

        if ($quarterContent !== '') {
            $absolutePath = $disk->path($quarterFilePath);
            $sourceId = $this->uploadToNlm($notebook, $absolutePath, "quarter-{$quarter->id}");
            $quarter->update([
                'nlm_source_id' => $sourceId,
                'status' => MdBundleStatus::Uploaded,
            ]);
        }

        // Clear the delta
        $delta->bundleItems()->delete();
        $delta->update([
            'word_count' => 0,
            'nlm_source_id' => null,
            'status' => MdBundleStatus::Pending,
        ]);
        $disk->put("{$notebook->id}/{$delta->id}.md", '');
    }

    /**
     * Freeze an active_quarter into frozen_quarter.
     */
    private function freezeQuarter(MdBundle $quarter): void
    {
        $quarter->update([
            'type' => MdBundleType::FrozenQuarter,
            'status' => MdBundleStatus::Uploaded,
        ]);
    }

    /**
     * Create a bundle from a collection of items, render, save to storage, upload to NLM.
     */
    private function createBundleAndUpload(Notebook $notebook, Collection $items, MdBundleType $type): MdBundle
    {
        $bundle = $this->createEmptyBundle($notebook, $type);

        $this->bundleItemsService->attachItems($bundle, $items);

        $content = $this->renderer->render($items);
        $wordCount = $this->wordCounter->count($content);

        $bundle->update(['word_count' => $wordCount]);

        // Save to storage
        $disk = Storage::disk('bundles');
        $disk->put($bundle->file_path, $content);

        // Upload to NLM
        $absolutePath = $disk->path($bundle->file_path);
        $sourceId = $this->uploadToNlm($notebook, $absolutePath, "bundle-{$bundle->id}");
        $bundle->update([
            'nlm_source_id' => $sourceId,
            'status' => MdBundleStatus::Uploaded,
        ]);

        return $bundle;
    }

    /**
     * Append an item to an existing bundle (update file + NLM).
     */
    private function appendItemToBundle(MdBundle $bundle, OriginalItem $item): void
    {
        $this->bundleItemsService->attachItems($bundle, collect([$item]));

        $newItemContent = $this->renderer->render(collect([$item]));
        $wordCount = $this->wordCounter->count($newItemContent);
        $bundle->increment('word_count', $wordCount);

        // Update file on disk
        $notebook = $bundle->notebook;
        $disk = Storage::disk('bundles');
        $filePath = "{$notebook->id}/{$bundle->id}.md";
        $existingContent = $disk->get($filePath) ?? '';

        $separator = $existingContent === '' ? '' : "\n\n";
        $disk->put($filePath, $existingContent.$separator.$newItemContent);

        // Replace source in NLM
        if ($bundle->nlm_source_id) {
            $this->deleteNlmSource($notebook, $bundle->nlm_source_id);
        }

        $absolutePath = $disk->path($filePath);
        $newSourceId = $this->uploadToNlm($notebook, $absolutePath, "bundle-{$bundle->id}");
        $bundle->update(['nlm_source_id' => $newSourceId, 'status' => MdBundleStatus::Uploaded]);
    }

    /**
     * Upload bundle file to NLM and return the source ID.
     */
    private function uploadToNlm(Notebook $notebook, string $filePath, string $title): ?string
    {
        if ($notebook->nlm_notebook_id === null) {
            return null;
        }

        $techAccount = $notebook->techAccount;

        if ($techAccount === null) {
            Log::warning('No tech account found for notebook', [
                'notebook_id' => $notebook->id,
            ]);

            throw new \Exception('Tech account not found for notebook');
        }

        try {
            $source = $this->notebookLMService->addSourceFile(
                $techAccount->id,
                $notebook->nlm_notebook_id,
                $filePath,
                ['title' => $title],
            );

            return $source->id;
        } catch (\Throwable $e) {
            Log::error('Failed to upload bundle to NLM', [
                'notebook_id' => $notebook->id,
                'title' => $title,
                'file_path' => $filePath,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Delete a source from NLM.
     */
    private function deleteNlmSource(Notebook $notebook, string $sourceId): void
    {
        if ($notebook->nlm_notebook_id === null) {
            return;
        }

        $techAccount = $notebook->techAccount;

        if ($techAccount === null) {
            return;
        }

        try {
            $this->notebookLMService->deleteSource(
                $techAccount->id,
                $notebook->nlm_notebook_id,
                $sourceId,
            );
        } catch (\Throwable $e) {
            Log::warning('Failed to delete NLM source', [
                'source_id' => $sourceId,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Create an empty MdBundle record.
     */
    private function createEmptyBundle(Notebook $notebook, MdBundleType $type): MdBundle
    {
        $bundle = MdBundle::create([
            'notebook_id' => $notebook->id,
            'type' => $type,
            'file_path' => "{$notebook->id}/{$notebook->id}.md",
            'word_count' => 0,
            'status' => MdBundleStatus::Pending,
            'is_consolidating' => false,
        ]);

        // Update file_path with the actual bundle id after creation
        $bundle->update(['file_path' => "{$notebook->id}/{$bundle->id}.md"]);

        // Initialize empty file
        Storage::disk('bundles')->put($bundle->file_path, '');

        return $bundle;
    }

    /**
     * Wait for all bundle sources to be ready in NLM after upload.
     */
    private function waitForBundleSources(Notebook $notebook): void
    {
        $techAccount = $notebook->techAccount;

        if ($techAccount === null || $notebook->nlm_notebook_id === null) {
            return;
        }

        $sourceIds = $notebook->mdBundles()
            ->whereNotNull('nlm_source_id')
            ->pluck('nlm_source_id')
            ->toArray();

        if ($sourceIds === []) {
            return;
        }

        Log::info('Waiting for bundle sources to be ready', [
            'notebook_id' => $notebook->id,
            'source_ids' => $sourceIds,
        ]);

        $this->notebookLMService->waitForSources(
            $techAccount->id,
            $notebook->nlm_notebook_id,
            $sourceIds,
            ['timeout' => 120.0],
        );

        Log::info('All bundle sources are ready', [
            'notebook_id' => $notebook->id,
        ]);
    }
}
