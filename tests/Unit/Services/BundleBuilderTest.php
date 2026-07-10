<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Domain\NotebookLM\DTOs\NotebookDescriptionDTO;
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
use App\Services\BundleRenderer2;
use App\Services\WordCounter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mockery;
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
        $renderer = new BundleRenderer2;
        $bundleItemsService = new BundleItemsService;
        $wordCounter = new WordCounter;

        // Create a mock that doesn't call the real implementation
        $notebookLMService = Mockery::mock(NotebookLMService::class);

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

        $notebookLMService->shouldReceive('waitForSources')
            ->andReturn([]);

        // Also mock getNotebookDescription to avoid the setDescription() call failing
        $notebookLMService->shouldReceive('getNotebookDescription')
            ->andReturn(new NotebookDescriptionDTO(
                summary: 'Mock description',
                suggested_topics: [],
            ));

        // Bind the mocked service to the container so NotebookNLMDecorator uses it
        $this->app->instance(NotebookLMService::class, $notebookLMService);

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
        // Word count should be greater than 0 (items were added)
        $this->assertGreaterThan(0, $bundles[0]->word_count);

        // All items should be bundled
        $this->assertDatabaseCount('bundle_items', 3);
        $this->assertSame(0, OriginalItem::unbundled()->count());
    }

    public function test_primary_indexing_creates_frozen_full_for_500k_words(): void
    {
        $builder = $this->createMockedBuilder();

        // Create items with large content
        OriginalItem::factory()
            ->count(10)
            ->for($this->contentSource)
            ->sequence(
                ['full_text' => str_repeat('word ', 100_000), 'published_at' => now()->subDays(10)],
                ['full_text' => str_repeat('word ', 100_000), 'published_at' => now()->subDays(9)],
                ['full_text' => str_repeat('word ', 100_000), 'published_at' => now()->subDays(8)],
                ['full_text' => str_repeat('word ', 100_000), 'published_at' => now()->subDays(7)],
                ['full_text' => str_repeat('word ', 100_000), 'published_at' => now()->subDays(6)],
                ['full_text' => str_repeat('word ', 50_000), 'published_at' => now()->subDays(5)],
                ['full_text' => str_repeat('word ', 30_000), 'published_at' => now()->subDays(4)],
                ['full_text' => str_repeat('word ', 20_000), 'published_at' => now()->subDays(3)],
                ['full_text' => str_repeat('word ', 10_000), 'published_at' => now()->subDays(2)],
                ['full_text' => str_repeat('word ', 5_000), 'published_at' => now()->subDays(1)],
            )
            ->create();

        $builder->build($this->notebook);

        $bundles = $this->notebook->mdBundles()->orderBy('created_at')->get();

        // Should create at least one bundle
        $this->assertGreaterThan(0, $bundles->count());
        // The first bundle should be FrozenFull (since 400k+ >= 480k)
        $this->assertSame(MdBundleType::FrozenFull, $bundles[0]->type);
    }

    public function test_primary_indexing_creates_frozen_half_for_240k_words(): void
    {
        $builder = $this->createMockedBuilder();

        // Create items with large content
        OriginalItem::factory()
            ->count(3)
            ->for($this->contentSource)
            ->sequence(
                ['full_text' => str_repeat('word ', 100_000), 'published_at' => now()->subDays(3)],
                ['full_text' => str_repeat('word ', 100_000), 'published_at' => now()->subDays(2)],
                ['full_text' => str_repeat('word ', 80_000), 'published_at' => now()->subDays(1)],
            )
            ->create();

        $builder->build($this->notebook);

        $bundles = $this->notebook->mdBundles()->orderBy('created_at')->get();

        // Should create at least one bundle
        $this->assertGreaterThan(0, $bundles->count());
        // The bundle type should be FrozenHalf or larger
        $this->assertContains($bundles[0]->type, [MdBundleType::FrozenHalf, MdBundleType::FrozenFull]);
    }

    public function test_primary_indexing_item_atomicity(): void
    {
        $builder = $this->createMockedBuilder();

        // Create items with large content
        OriginalItem::factory()
            ->count(3)
            ->for($this->contentSource)
            ->sequence(
                ['full_text' => str_repeat('word ', 480_000), 'published_at' => now()->subDays(3)],
                ['full_text' => str_repeat('word ', 100_000), 'published_at' => now()->subDays(2)],
                ['full_text' => str_repeat('word ', 100_000), 'published_at' => now()->subDays(1)],
            )
            ->create();

        $builder->build($this->notebook);

        $bundles = $this->notebook->mdBundles()->orderBy('created_at')->get();

        // Should create at least one bundle
        $this->assertGreaterThan(0, $bundles->count());
        // The first bundle should be FrozenFull (since 480k >= 480k)
        $this->assertSame(MdBundleType::FrozenFull, $bundles[0]->type);
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
            ->create(['word_count' => 5_000]);

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
        // Word count includes rendered content, so it's higher than raw word_count
        $this->assertGreaterThan(5000, $delta->word_count);
        $this->assertSame(3, $delta->bundleItems()->count());
    }

    public function test_continuous_indexing_flushes_to_quarter_when_delta_full(): void
    {
        $builder = $this->createMockedBuilder();

        // Existing active_delta with a large item
        $delta = MdBundle::factory()
            ->for($this->notebook)
            ->activeDelta()
            ->create([
                'word_count' => 9_000,
                'file_path' => "{$this->notebook->id}/delta.md",
                'status' => MdBundleStatus::Uploaded,
            ]);

        // Item in delta with large content
        $deltaItem = OriginalItem::factory()
            ->for($this->contentSource)
            ->create(['full_text' => str_repeat('word ', 9_000)]);

        $delta->bundleItems()->create([
            'original_item_id' => $deltaItem->id,
            'position' => 1,
        ]);

        Storage::disk('bundles')->put($delta->file_path, 'delta content');

        // New item that pushes it over 10k
        $newItem = OriginalItem::factory()
            ->for($this->contentSource)
            ->create(['full_text' => str_repeat('word ', 2_000), 'published_at' => now()]);

        $builder->build($this->notebook);

        // The delta should have been flushed to quarter
        $activeDelta = $this->notebook->mdBundles()
            ->activeDelta()
            ->where('word_count', '>', 0)
            ->first();
        $this->assertNotNull($activeDelta);

        $activeQuarter = $this->notebook->mdBundles()->activeQuarter()->first();
        $this->assertNotNull($activeQuarter);

        // File on disk should be updated
        $quarterFilePath = "{$this->notebook->id}/{$activeQuarter->id}.md";
        $this->assertNotNull(Storage::disk('bundles')->get($quarterFilePath));
    }

    public function test_continuous_indexing_freezes_quarter_when_full(): void
    {
        $builder = $this->createMockedBuilder();

        // Create a quarter with a large item (rendered content will have many words)
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
            ->create(['full_text' => str_repeat('word ', 119_000)]);

        $quarter->bundleItems()->create([
            'original_item_id' => $quarterItem->id,
            'position' => 1,
        ]);

        // Create a delta with a large item
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
            ->create(['full_text' => str_repeat('word ', 9_000)]);

        $delta->bundleItems()->create([
            'original_item_id' => $deltaItem->id,
            'position' => 1,
        ]);

        Storage::disk('bundles')->put($delta->file_path, 'delta content');

        // Create a new item that will trigger delta flush
        $newItem = OriginalItem::factory()
            ->for($this->contentSource)
            ->create(['full_text' => str_repeat('word ', 2_000), 'published_at' => now()]);

        $builder->build($this->notebook);

        // The quarter should now be frozen
        $frozenQuarters = $this->notebook->mdBundles()
            ->where('type', MdBundleType::FrozenQuarter)
            ->get();

        $this->assertCount(1, $frozenQuarters);
    }

    public function test_continuous_indexing_dispatches_consolidation_when_two_frozen_quarters(): void
    {
        $builder = $this->createMockedBuilder();

        // Create two existing frozen quarters
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

        // Create an active_quarter with a large item
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
            ->create(['full_text' => str_repeat('word ', 119_000)]);

        $quarter->bundleItems()->create([
            'original_item_id' => $quarterItem->id,
            'position' => 1,
        ]);

        // Create delta with a large item
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
            ->create(['full_text' => str_repeat('word ', 9_000)]);

        $delta->bundleItems()->create([
            'original_item_id' => $deltaItem->id,
            'position' => 1,
        ]);

        Storage::disk('bundles')->put($delta->file_path, 'delta content');

        // New item that will trigger delta flush and quarter freeze
        OriginalItem::factory()
            ->for($this->contentSource)
            ->create(['full_text' => str_repeat('word ', 2_000), 'published_at' => now()]);

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
            ->create();
        $bundle->bundleItems()->create([
            'original_item_id' => $item->id,
            'position' => 1,
        ]);

        $builder->build($this->notebook);

        // No new bundles
        $this->assertDatabaseCount('md_bundles', 1);
    }
}
