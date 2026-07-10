<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\NotebookLM\NotebookLMService;
use App\Enums\MdBundleStatus;
use App\Enums\MdBundleType;
use App\Jobs\ConsolidateBundlesJob;
use App\Models\MdBundle;
use App\Models\Notebook;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class BundleConsolidationService
{
    public function __construct(
        private readonly BundleRenderer $renderer,
        private readonly WordCounter $wordCounter,
        private readonly NotebookLMService $notebookLMService,
    ) {}

    /**
     * Scan all notebooks and dispatch consolidation jobs when eligible bundles are found.
     * This method contains the business logic extracted from ScanForConsolidationJob.
     */
    public function scanAndDispatchConsolidation(): void
    {
        // Check all notebooks for consolidation opportunities
        $notebooks = Notebook::whereHas('mdBundles', function ($query) {
            $query->whereIn('type', [MdBundleType::FrozenQuarter, MdBundleType::FrozenHalf])
                ->where('is_consolidating', false);
        })->get();

        foreach ($notebooks as $notebook) {
            $this->checkAndDispatchConsolidation($notebook);
        }

        Log::info('BundleConsolidationService: Completed consolidation scan', [
            'notebooks_checked' => $notebooks->count(),
        ]);
    }

    /**
     * Check if a specific notebook needs consolidation and dispatch job if needed.
     */
    private function checkAndDispatchConsolidation(Notebook $notebook): void
    {
        // Check for quarter_to_half consolidation
        $quarterCount = $notebook->mdBundles()
            ->where('type', MdBundleType::FrozenQuarter)
            ->where('is_consolidating', false)
            ->count();

        if ($quarterCount >= 2) {
            ConsolidateBundlesJob::dispatch($notebook->id, 'quarter_to_half');
            Log::info('BundleConsolidationService: Dispatched quarter_to_half consolidation', [
                'notebook_id' => $notebook->id,
                'bundles_count' => $quarterCount,
            ]);
        }

        // Check for half_to_full consolidation
        $halfCount = $notebook->mdBundles()
            ->where('type', MdBundleType::FrozenHalf)
            ->where('is_consolidating', false)
            ->count();

        if ($halfCount >= 2) {
            ConsolidateBundlesJob::dispatch($notebook->id, 'half_to_full');
            Log::info('BundleConsolidationService: Dispatched half_to_full consolidation', [
                'notebook_id' => $notebook->id,
                'bundles_count' => $halfCount,
            ]);
        }
    }

    /**
     * Consolidate two bundles into one (atomic swap with NLM).
     *
     * @param  MdBundleType  $sourceType  Type of source bundles (FrozenQuarter or FrozenHalf)
     */
    public function consolidate(Notebook $notebook, MdBundleType $sourceType): void
    {
        $targetType = $sourceType->consolidationTarget();

        // Find two oldest bundles of source type that are not consolidating
        $sourceIds = $notebook->mdBundles()
            ->where('type', $sourceType)
            ->where('is_consolidating', false)
            ->orderBy('created_at')
            ->limit(2)
            ->pluck('id')
            ->toArray();

        if (count($sourceIds) < 2) {
            return;
        }

        $sources = MdBundle::whereIn('id', $sourceIds)->get();

        DB::transaction(function () use ($notebook, $targetType, $sources): void {
            // Mark both as consolidating
            $sources->each->update(['is_consolidating' => true]);

            [$first, $second] = [$sources[0], $sources[1]];

            // Get all items from both bundles, ordered by position
            $firstItems = $first->bundleItems()->with('originalItem')->orderBy('position')->get();
            $secondItems = $second->bundleItems()->with('originalItem')->orderBy('position')->get();

            $allItems = $firstItems->merge($secondItems)->pluck('originalItem');

            $totalWordCount = $allItems->sum(
                fn ($item) => $item->word_count ?? $this->wordCounter->count($item->full_text ?? '')
            );

            // Create the consolidated bundle with status 'uploading'
            $target = MdBundle::create([
                'notebook_id' => $notebook->id,
                'type' => $targetType,
                'file_path' => "{$notebook->id}/{$notebook->id}.md",
                'word_count' => 0,
                'status' => MdBundleStatus::Uploading,
                'is_consolidating' => false,
            ]);

            // Update file_path with actual id
            $target->update(['file_path' => "{$notebook->id}/{$target->id}.md"]);

            // Reassign all bundle_items to the new bundle
            $position = 0;
            foreach ($allItems as $item) {
                $position++;

                // Create new bundle_item record
                $target->bundleItems()->create([
                    'original_item_id' => $item->id,
                    'position' => $position,
                ]);
            }

            $target->update(['word_count' => $totalWordCount]);

            // Render and save the consolidated file
            $content = $this->renderer->render($allItems);
            $disk = Storage::disk('bundles');
            $disk->put($target->file_path, $content);

            // STEP 6: Upload new bundle to NLM (old bundles still exist in NLM)
            try {
                $sourceDTO = $this->notebookLMService->addSourceFile(
                    $notebook->tech_account_id,
                    $notebook->nlm_notebook_id,
                    $target->file_path,
                    ['wait' => true, 'wait_timeout' => 60]
                );

                // STEP 7: Update new bundle with NLM source ID and status
                $target->update([
                    'nlm_source_id' => $sourceDTO->id,
                    'status' => MdBundleStatus::Uploaded,
                ]);
            } catch (\Throwable $e) {
                // If upload fails, mark as error and rethrow
                $target->update(['status' => MdBundleStatus::Error, 'error_code' => 'upload_failed']);

                throw $e;
            }
        });

        // STEP 9: Delete old bundles from NLM (outside transaction to avoid rollback on NLM errors)
        $this->deleteOldBundlesFromNLM($notebook, $sources ?? []);

        // STEP 10 & 11: Delete old files and DB records
        $this->cleanupOldBundles($sources ?? []);

        Log::info('BundleConsolidationService: Bundles consolidated successfully', [
            'notebook_id' => $notebook->id,
            'source_type' => $sourceType->value,
            'target_type' => $targetType->value,
        ]);
    }

    /**
     * Delete old bundles from NLM.
     *
     * @param  Collection<int, MdBundle>  $oldBundles
     */
    private function deleteOldBundlesFromNLM(Notebook $notebook, $oldBundles): void
    {
        foreach ($oldBundles as $old) {
            if (empty($old->nlm_source_id)) {
                continue;
            }

            $this->notebookLMService->deleteSource(
                $notebook->tech_account_id,
                $notebook->nlm_notebook_id,
                $old->nlm_source_id
            );
        }
    }

    /**
     * Delete old bundle files and DB records.
     *
     * @param  Collection<int, MdBundle>  $oldBundles
     */
    private function cleanupOldBundles($oldBundles): void
    {
        $disk = Storage::disk('bundles');

        foreach ($oldBundles as $old) {
            $disk->delete($old->file_path);
            $old->delete(); // Cascades to old bundle_items
        }
    }
}
