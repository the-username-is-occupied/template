<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\MdBundleStatus;
use App\Enums\MdBundleType;
use App\Models\MdBundle;
use App\Models\Notebook;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class BundleConsolidationService
{
    public function __construct(
        private readonly BundleRenderer $renderer,
        private readonly WordCounter $wordCounter,
    ) {}

    /**
     * Consolidate two frozen quarter bundles into a half bundle.
     */
    public function consolidate(Notebook $notebook): void
    {
        DB::transaction(function () use ($notebook): void {
            // Find two oldest frozen_quarter bundles that are not consolidating
            $frozenQuarters = $notebook->mdBundles()
                ->where('type', MdBundleType::FrozenQuarter)
                ->where('is_consolidating', false)
                ->orderBy('created_at')
                ->limit(2)
                ->get();

            if ($frozenQuarters->count() < 2) {
                return;
            }

            // Mark both as consolidating
            $frozenQuarters->each->update(['is_consolidating' => true]);

            [$first, $second] = [$frozenQuarters[0], $frozenQuarters[1]];

            // Get all items from both bundles, ordered by position
            $firstItems = $first->bundleItems()->with('originalItem')->orderBy('position')->get();
            $secondItems = $second->bundleItems()->with('originalItem')->orderBy('position')->get();

            $allItems = $firstItems->merge($secondItems)->pluck('originalItem');

            $totalWordCount = $allItems->sum(
                fn ($item) => $item->word_count ?? $this->wordCounter->count($item->full_text ?? '')
            );

            // Determine the target bundle type
            $targetType = match (true) {
                $totalWordCount >= 480_000 => MdBundleType::FrozenHalf,
                default => MdBundleType::FrozenHalf, // Two quarters always make a half
            };

            // Create the consolidated bundle
            $target = MdBundle::create([
                'notebook_id' => $notebook->id,
                'type' => $targetType,
                'file_path' => "{$notebook->id}/{$notebook->id}.md",
                'word_count' => 0,
                'status' => MdBundleStatus::Pending,
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

                // Update original_item reference
                $item->update(['md_bundle_id' => $target->id]);
            }

            $target->update(['word_count' => $totalWordCount]);

            // Render and save the consolidated file
            $content = $this->renderer->render($allItems);
            $disk = Storage::disk('bundles');
            $disk->put($target->file_path, $content);

            // Clean up old bundles (files + DB records)
            foreach ([$first, $second] as $old) {
                $disk->delete($old->file_path);
                $old->delete(); // cascades to old bundle_items
            }

            Log::info('BundleConsolidationService: Two frozen_quarter bundles consolidated', [
                'notebook_id' => $notebook->id,
                'target_bundle_id' => $target->id,
                'total_word_count' => $totalWordCount,
                'bundles_merged' => [$first->id, $second->id],
                'target_type' => $targetType->value,
            ]);
        });
    }
}
