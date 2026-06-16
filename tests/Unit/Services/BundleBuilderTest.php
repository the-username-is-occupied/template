<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Domain\NotebookLM\DTOs\SourceDTO;
use App\Domain\NotebookLM\NotebookLMService;
use App\Enums\MdBundleStatus;
use App\Enums\MdBundleType;
use App\Jobs\ConsolidateBundlesJob;
use App\Models\ContentSource;
use App\Models\MdBundle;
use App\Models\Notebook;
use App\Models\OriginalItem;
use App\Services\BundleBuilder;
use App\Services\BundleItemsService;
use App\Services\BundleRenderer;
use App\Services\WordCounter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

final class BundleBuilderTest extends TestCase
{
    use RefreshDatabase;

    private Notebook $notebook;

    private ContentSource $contentSource;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('bundles');

        $this->notebook = Notebook::factory()->create();
        $this->contentSource = ContentSource::factory()->create();
        $this->notebook->contentSources()->attach($this->contentSource, [
            'id' => (string) Str::uuid(),
        ]);
    }

    private function createMockedBuilder(): BundleBuilder
    {
        $renderer = new BundleRenderer;
        $bundleItemsService = new BundleItemsService;
        $wordCounter = new WordCounter;
        $notebookLMService = $this->mock(NotebookLMService::class);

        // NLM is mocked — no actual calls expected in unit tests
        $notebookLMService->shouldReceive('addSourceFile')
            ->andReturn(new SourceDTO(
                id: 'nlm-source-'.fake()->uuid(),
                title: 'mocked',
                url: null,
                created_at: now()->toIso8601String(),
                status: '1',
                kind: 'text',
            ));

        $notebookLMService->shouldReceive('deleteSource')
            ->andReturn(true);

        return new BundleBuilder(
            $renderer,
            $bundleItemsService,
            $wordCounter,
            $notebookLMService,
        );
    }

    // =========================================================================
    // Primary Indexing Tests
    // =========================================================================

    public function test_primary_indexing_creates_active_delta_for_small_amount(): void
    {
        $builder = $this->createMockedBuilder();

        // Create items totaling < 10k words
        OriginalItem::factory()
            ->count(3)
            ->for($this->contentSource)
            ->sequence(
                ['word_count' => 100, 'published_at' => now()->subDays(3)],
                ['word_count' => 200, 'published_at' => now()->subDays(2)],
                ['word_count' => 150, 'published_at' => now()->subDays(1)],
            )
            ->create();

        $builder->build($this->notebook);

        // Should create one active_delta bundle
        $bundles = $this->notebook->mdBundles()->get();
        $this->assertCount(1, $bundles);
        $this->assertSame(MdBundleType::ActiveDelta, $bundles[0]->type);
        $this->assertSame(450, $bundles[0]->word_count);

        // All items should be bundled
        $this->assertDatabaseCount('bundle_items', 3);
        $this->assertSame(0, OriginalItem::unbundled()->count());
    }

    public function test_primary_indexing_creates_frozen_full_for_500k_words(): void
    {
        $builder = $this->createMockedBuilder();

        // Create items totaling 500k+ words (enough for 1 frozen_full + remainder)
        OriginalItem::factory()
            ->count(10)
            ->for($this->contentSource)
            ->sequence(
                ['word_count' => 100_000, 'published_at' => now()->subDays(10)],
                ['word_count' => 100_000, 'published_at' => now()->subDays(9)],
                ['word_count' => 100_000, 'published_at' => now()->subDays(8)],
                ['word_count' => 100_000, 'published_at' => now()->subDays(7)],
                ['word_count' => 100_000, 'published_at' => now()->subDays(6)],
                ['word_count' => 50_000, 'published_at' => now()->subDays(5)],
                ['word_count' => 30_000, 'published_at' => now()->subDays(4)],
                ['word_count' => 20_000, 'published_at' => now()->subDays(3)],
                ['word_count' => 10_000, 'published_at' => now()->subDays(2)],
                ['word_count' => 5_000, 'published_at' => now()->subDays(1)],
            )
            ->create();

        $builder->build($this->notebook);

        // Expect: items 1-4 (400k) in frozen_full, item 5 triggers overflow
        // Items 5-10 (215k) → frozen_quarter (>=120k, <240k)
        $bundles = $this->notebook->mdBundles()->orderBy('created_at')->get();

        $this->assertCount(2, $bundles);
        $this->assertSame(MdBundleType::FrozenFull, $bundles[0]->type);
        $this->assertSame(400_000, $bundles[0]->word_count);

        // Remainder 215k → frozen_quarter
        $this->assertSame(MdBundleType::FrozenQuarter, $bundles[1]->type);
        $this->assertSame(215_000, $bundles[1]->word_count);

        $this->assertSame(0, OriginalItem::unbundled()->count());
    }

    public function test_primary_indexing_creates_frozen_half_for_240k_words(): void
    {
        $builder = $this->createMockedBuilder();

        OriginalItem::factory()
            ->count(3)
            ->for($this->contentSource)
            ->sequence(
                ['word_count' => 100_000, 'published_at' => now()->subDays(3)],
                ['word_count' => 100_000, 'published_at' => now()->subDays(2)],
                ['word_count' => 80_000, 'published_at' => now()->subDays(1)],
            )
            ->create();

        $builder->build($this->notebook);

        $bundles = $this->notebook->mdBundles()->orderBy('created_at')->get();

        // 280k total → frozen_half (240k-479k)
        $this->assertCount(1, $bundles);
        $this->assertSame(MdBundleType::FrozenHalf, $bundles[0]->type);
        $this->assertSame(280_000, $bundles[0]->word_count);
    }

    public function test_primary_indexing_item_atomicity(): void
    {
        $builder = $this->createMockedBuilder();

        // 480k + 100k + 100k — first item alone is enough to start a frozen_full
        // The 480k item plus 100k would exceed 480k, but 480k < 480k? No, 480k == FROZEN_FULL_MAX_WORDS
        // Actually our condition is >= so 480k checks: accumulator is empty, push 480k
        // Then accumulator has one item (480k), next item is 100k, accWords + itemWords = 480k + 100k >= 480k → create frozen_full
        // Actually wait: condition is $accumulator->isNotEmpty() && $accWords + $itemWords >= self::FROZEN_FULL_MAX_WORDS
        // So item 1: accumulator empty, push, accWords=480k
        // Item 2: accumulator not empty, 480k+100k >= 480k → create frozen_full with item 1
        // Then accumulator = collect([item2]), accWords=100k
        // Remainder 100k → active_delta

        OriginalItem::factory()
            ->count(3)
            ->for($this->contentSource)
            ->sequence(
                ['word_count' => 480_000, 'published_at' => now()->subDays(3)],
                ['word_count' => 100_000, 'published_at' => now()->subDays(2)],
                ['word_count' => 100_000, 'published_at' => now()->subDays(1)],
            )
            ->create();

        $builder->build($this->notebook);

        $bundles = $this->notebook->mdBundles()->orderBy('created_at')->get();

        // frozen_full with 480k item, then remainder 200k → frozen_half
        $this->assertCount(2, $bundles);
        $this->assertSame(MdBundleType::FrozenFull, $bundles[0]->type);
        $this->assertSame(480_000, $bundles[0]->word_count);

        $this->assertSame(MdBundleType::FrozenQuarter, $bundles[1]->type);
        $this->assertSame(200_000, $bundles[1]->word_count);
    }

    // =========================================================================
    // Continuous Indexing Tests
    // =========================================================================

    public function test_continuous_indexing_appends_to_existing_active_delta(): void
    {
        $builder = $this->createMockedBuilder();

        // Pre-create an active_delta bundle with some items
        $existingDelta = MdBundle::factory()
            ->for($this->notebook)
            ->activeDelta()
            ->create([
                'word_count' => 5_000,
                'file_path' => "{$this->notebook->id}/delta-test.md",
                'status' => MdBundleStatus::Uploaded,
            ]);

        // Create a bundle_items for the existing delta
        $existingItem = OriginalItem::factory()
            ->for($this->contentSource)
            ->create(['word_count' => 5_000, 'md_bundle_id' => $existingDelta->id]);

        $existingDelta->bundleItems()->create([
            'original_item_id' => $existingItem->id,
            'position' => 1,
        ]);

        // Initialize the delta file on disk
        Storage::disk('bundles')->put($existingDelta->file_path, 'existing content');

        // New items to add
        OriginalItem::factory()
            ->count(2)
            ->for($this->contentSource)
            ->sequence(
                ['word_count' => 2_000, 'published_at' => now()->subDay()],
                ['word_count' => 1_000, 'published_at' => now()],
            )
            ->create();

        $builder->build($this->notebook);

        $delta = $this->notebook->mdBundles()->activeDelta()->first();
        $this->assertNotNull($delta);
        $this->assertSame(8_000, $delta->word_count);
        $this->assertSame(3, $delta->bundleItems()->count());
    }

    public function test_continuous_indexing_flushes_to_quarter_when_delta_full(): void
    {
        $builder = $this->createMockedBuilder();

        // Existing active_delta is almost full (9k out of 10k)
        $delta = MdBundle::factory()
            ->for($this->notebook)
            ->activeDelta()
            ->create([
                'word_count' => 9_000,
                'file_path' => "{$this->notebook->id}/delta.md",
                'status' => MdBundleStatus::Uploaded,
            ]);

        // Item in delta
        $deltaItem = OriginalItem::factory()
            ->for($this->contentSource)
            ->create(['word_count' => 9_000, 'md_bundle_id' => $delta->id]);

        $delta->bundleItems()->create([
            'original_item_id' => $deltaItem->id,
            'position' => 1,
        ]);

        Storage::disk('bundles')->put($delta->file_path, 'delta content');

        // New item that pushes it over 10k
        $newItem = OriginalItem::factory()
            ->for($this->contentSource)
            ->create(['word_count' => 2_000, 'published_at' => now()]);

        $builder->build($this->notebook);

        // The delta should have been flushed to quarter
        $activeDelta = $this->notebook->mdBundles()
            ->activeDelta()
            ->where('word_count', '>', 0)
            ->first();
        $this->assertNotNull($activeDelta);
        $this->assertSame(2_000, $activeDelta->word_count); // Only the new item

        $activeQuarter = $this->notebook->mdBundles()->activeQuarter()->first();
        $this->assertNotNull($activeQuarter);
        $this->assertSame(9_000, $activeQuarter->word_count);

        // File on disk should be updated
        $quarterFilePath = "{$this->notebook->id}/{$activeQuarter->id}.md";
        $this->assertNotNull(Storage::disk('bundles')->get($quarterFilePath));
    }

    public function test_continuous_indexing_freezes_quarter_when_full(): void
    {
        $builder = $this->createMockedBuilder();

        // Existing active_quarter is almost full (119k out of 120k)
        $quarter = MdBundle::factory()
            ->for($this->notebook)
            ->activeQuarter()
            ->create([
                'word_count' => 119_000,
                'file_path' => "{$this->notebook->id}/quarter.md",
                'status' => MdBundleStatus::Uploaded,
            ]);

        $quarterItem = OriginalItem::factory()
            ->for($this->contentSource)
            ->create(['word_count' => 119_000, 'md_bundle_id' => $quarter->id]);

        $quarter->bundleItems()->create([
            'original_item_id' => $quarterItem->id,
            'position' => 1,
        ]);

        // Existing delta
        $delta = MdBundle::factory()
            ->for($this->notebook)
            ->activeDelta()
            ->create([
                'word_count' => 9_000,
                'file_path' => "{$this->notebook->id}/delta.md",
                'status' => MdBundleStatus::Uploaded,
            ]);

        $deltaItem = OriginalItem::factory()
            ->for($this->contentSource)
            ->create(['word_count' => 9_000, 'md_bundle_id' => $delta->id]);

        $delta->bundleItems()->create([
            'original_item_id' => $deltaItem->id,
            'position' => 1,
        ]);

        Storage::disk('bundles')->put($delta->file_path, 'delta content');

        // New item that fills delta, triggering flush to quarter which is now full
        $newItem = OriginalItem::factory()
            ->for($this->contentSource)
            ->create(['word_count' => 2_000, 'published_at' => now()]);

        $builder->build($this->notebook);

        // The quarter should now be frozen
        $frozenQuarters = $this->notebook->mdBundles()
            ->where('type', MdBundleType::FrozenQuarter)
            ->get();

        $this->assertCount(1, $frozenQuarters);
        $this->assertSame(119_000, $frozenQuarters[0]->word_count);

        // New quarter should exist
        $activeQuarter = $this->notebook->mdBundles()->activeQuarter()->first();
        $this->assertNotNull($activeQuarter);
        $this->assertSame(9_000, $activeQuarter->word_count);
    }

    public function test_continuous_indexing_dispatches_consolidation_when_two_frozen_quarters(): void
    {
        // We can't easily mock the job dispatch in the current setup,
        // but we can test that it creates the second frozen quarter
        $builder = $this->createMockedBuilder();

        // Create two existing frozen quarters (simulating previous freeze)
        $frozen1 = MdBundle::factory()
            ->for($this->notebook)
            ->create([
                'type' => MdBundleType::FrozenQuarter,
                'word_count' => 120_000,
                'status' => MdBundleStatus::Uploaded,
            ]);

        $frozen2 = MdBundle::factory()
            ->for($this->notebook)
            ->create([
                'type' => MdBundleType::FrozenQuarter,
                'word_count' => 120_000,
                'status' => MdBundleStatus::Uploaded,
            ]);

        // Create an active_quarter that's almost full
        $quarter = MdBundle::factory()
            ->for($this->notebook)
            ->activeQuarter()
            ->create([
                'word_count' => 119_000,
                'file_path' => "{$this->notebook->id}/quarter2.md",
                'status' => MdBundleStatus::Uploaded,
            ]);

        $quarterItem = OriginalItem::factory()
            ->for($this->contentSource)
            ->create(['word_count' => 119_000, 'md_bundle_id' => $quarter->id]);

        $quarter->bundleItems()->create([
            'original_item_id' => $quarterItem->id,
            'position' => 1,
        ]);

        // Create delta to flush
        $delta = MdBundle::factory()
            ->for($this->notebook)
            ->activeDelta()
            ->create([
                'word_count' => 9_000,
                'file_path' => "{$this->notebook->id}/delta2.md",
                'status' => MdBundleStatus::Uploaded,
            ]);

        $deltaItem = OriginalItem::factory()
            ->for($this->contentSource)
            ->create(['word_count' => 9_000, 'md_bundle_id' => $delta->id]);

        $delta->bundleItems()->create([
            'original_item_id' => $deltaItem->id,
            'position' => 1,
        ]);

        Storage::disk('bundles')->put($delta->file_path, 'delta content');

        // New item
        OriginalItem::factory()
            ->for($this->contentSource)
            ->create(['word_count' => 2_000, 'published_at' => now()]);

        // We need to actually mock the Queue facade to check for dispatch
        // For now, just verify the third frozen quarter is created (total 3)
        Queue::fake();

        $builder->build($this->notebook);

        // Check that ConsolidateBundlesJob was dispatched
        Queue::assertPushed(ConsolidateBundlesJob::class, function ($job) {
            return $job->notebookId === $this->notebook->id;
        });
    }

    public function test_build_does_nothing_when_no_unbundled_items(): void
    {
        $builder = $this->createMockedBuilder();

        // All items are already bundled
        $bundle = MdBundle::factory()
            ->for($this->notebook)
            ->activeDelta()
            ->create();

        $item = OriginalItem::factory()
            ->for($this->contentSource)
            ->create(['md_bundle_id' => $bundle->id]);

        $builder->build($this->notebook);

        // No new bundles
        $this->assertDatabaseCount('md_bundles', 1);
    }
}
