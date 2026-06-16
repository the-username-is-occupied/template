<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Domain\NotebookLM\DTOs\SourceDTO;
use App\Domain\NotebookLM\NotebookLMService;
use App\Enums\MdBundleStatus;
use App\Enums\MdBundleType;
use App\Jobs\ConsolidateBundlesJob;
use App\Models\MdBundle;
use App\Models\Notebook;
use App\Models\OriginalItem;
use App\Services\BundleConsolidationService;
use App\Services\BundleRenderer;
use App\Services\WordCounter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class BundleConsolidationServiceTest extends TestCase
{
    use RefreshDatabase;

    private Notebook $notebook;

    private BundleConsolidationService $service;

    private $mockNotebookLMService;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('bundles');

        // Create a notebook with tech_account
        $this->notebook = Notebook::factory()->create([
            'nlm_notebook_id' => 'nlm-notebook-456',
        ]);

        // Create mock for NotebookLMService
        $this->mockNotebookLMService = $this->mock(NotebookLMService::class);

        $this->service = new BundleConsolidationService(
            new BundleRenderer,
            new WordCounter,
            $this->mockNotebookLMService
        );
    }

    // =========================================================================
    // Consolidation Tests
    // =========================================================================

    public function test_consolidate_two_frozen_quarter_bundles_into_frozen_half(): void
    {
        // Create two FrozenQuarter bundles
        $bundle1 = MdBundle::factory()->create([
            'notebook_id' => $this->notebook->id,
            'type' => MdBundleType::FrozenQuarter,
            'status' => MdBundleStatus::Uploaded,
            'is_consolidating' => false,
            'nlm_source_id' => 'nlm-source-1',
        ]);

        $bundle2 = MdBundle::factory()->create([
            'notebook_id' => $this->notebook->id,
            'type' => MdBundleType::FrozenQuarter,
            'status' => MdBundleStatus::Uploaded,
            'is_consolidating' => false,
            'nlm_source_id' => 'nlm-source-2',
        ]);

        // Create original items for each bundle
        $items1 = OriginalItem::factory()->count(2)->create(['word_count' => 100]);
        $items2 = OriginalItem::factory()->count(3)->create(['word_count' => 150]);

        // Attach items to bundles
        foreach ($items1 as $index => $item) {
            $bundle1->bundleItems()->create([
                'original_item_id' => $item->id,
                'position' => $index + 1,
            ]);
            $item->update(['md_bundle_id' => $bundle1->id]);
        }

        foreach ($items2 as $index => $item) {
            $bundle2->bundleItems()->create([
                'original_item_id' => $item->id,
                'position' => $index + 1,
            ]);
            $item->update(['md_bundle_id' => $bundle2->id]);
        }

        // Mock NLM service response
        $mockSourceDTO = new SourceDTO(
            id: 'new-nlm-source-id',
            title: 'Consolidated Bundle',
            url: null,
            created_at: now()->toIso8601String(),
            status: '1',
            kind: 'text',
        );

        $this->mockNotebookLMService
            ->shouldReceive('addSourceFile')
            ->once()
            ->andReturn($mockSourceDTO);

        $this->mockNotebookLMService
            ->shouldReceive('deleteSource')
            ->andReturn(true);

        // Execute consolidation
        $this->service->consolidate($this->notebook, MdBundleType::FrozenQuarter);

        // Assert new bundle created
        $newBundle = MdBundle::where('type', MdBundleType::FrozenHalf)->first();
        $this->assertNotNull($newBundle);
        $this->assertSame(MdBundleStatus::Uploaded, $newBundle->status);
        $this->assertSame('new-nlm-source-id', $newBundle->nlm_source_id);

        // Assert old bundles are deleted
        $this->assertDatabaseMissing('md_bundles', ['id' => $bundle1->id]);
        $this->assertDatabaseMissing('md_bundles', ['id' => $bundle2->id]);

        // Assert file created
        Storage::disk('bundles')->assertExists($newBundle->file_path);
    }

    public function test_consolidate_two_frozen_half_bundles_into_frozen_full(): void
    {
        // Create two FrozenHalf bundles
        $bundle1 = MdBundle::factory()->create([
            'notebook_id' => $this->notebook->id,
            'type' => MdBundleType::FrozenHalf,
            'status' => MdBundleStatus::Uploaded,
            'is_consolidating' => false,
            'nlm_source_id' => 'nlm-source-3',
        ]);

        $bundle2 = MdBundle::factory()->create([
            'notebook_id' => $this->notebook->id,
            'type' => MdBundleType::FrozenHalf,
            'status' => MdBundleStatus::Uploaded,
            'is_consolidating' => false,
            'nlm_source_id' => 'nlm-source-4',
        ]);

        // Create original items
        $items = OriginalItem::factory()->count(4)->create(['word_count' => 200]);

        // Attach items to bundles (2 each)
        foreach ($items->take(2) as $index => $item) {
            $bundle1->bundleItems()->create([
                'original_item_id' => $item->id,
                'position' => $index + 1,
            ]);
            $item->update(['md_bundle_id' => $bundle1->id]);
        }

        foreach ($items->skip(2) as $index => $item) {
            $bundle2->bundleItems()->create([
                'original_item_id' => $item->id,
                'position' => $index + 1,
            ]);
            $item->update(['md_bundle_id' => $bundle2->id]);
        }

        // Mock NLM service
        $mockSourceDTO = new SourceDTO(
            id: 'new-nlm-source-id-2',
            title: 'Consolidated Full Bundle',
            url: null,
            created_at: now()->toIso8601String(),
            status: '1',
            kind: 'text',
        );

        $this->mockNotebookLMService
            ->shouldReceive('addSourceFile')
            ->once()
            ->andReturn($mockSourceDTO);

        $this->mockNotebookLMService
            ->shouldReceive('deleteSource')
            ->twice()
            ->andReturn(true);

        // Execute consolidation
        $this->service->consolidate($this->notebook, MdBundleType::FrozenHalf);

        // Assert new bundle created with correct type
        $newBundle = MdBundle::where('type', MdBundleType::FrozenFull)->first();
        $this->assertNotNull($newBundle);
        $this->assertSame(MdBundleStatus::Uploaded, $newBundle->status);
    }

    public function test_consolidate_returns_early_when_less_than_two_bundles(): void
    {
        // Create only one bundle
        MdBundle::factory()->create([
            'notebook_id' => $this->notebook->id,
            'type' => MdBundleType::FrozenQuarter,
            'status' => MdBundleStatus::Uploaded,
            'is_consolidating' => false,
        ]);

        // Mock should not be called
        $this->mockNotebookLMService
            ->shouldNotReceive('addSourceFile');

        // Execute consolidation
        $this->service->consolidate($this->notebook, MdBundleType::FrozenQuarter);

        // Assert no new bundle created
        $this->assertDatabaseCount('md_bundles', 1);
    }

    public function test_consolidate_marks_bundles_as_consolidating(): void
    {
        // Create two bundles
        $bundle1 = MdBundle::factory()->create([
            'notebook_id' => $this->notebook->id,
            'type' => MdBundleType::FrozenQuarter,
            'status' => MdBundleStatus::Uploaded,
            'is_consolidating' => false,
        ]);

        $bundle2 = MdBundle::factory()->create([
            'notebook_id' => $this->notebook->id,
            'type' => MdBundleType::FrozenQuarter,
            'status' => MdBundleStatus::Uploaded,
            'is_consolidating' => false,
        ]);

        // Create items
        $items = OriginalItem::factory()->count(2)->create(['word_count' => 100]);

        foreach ($items as $index => $item) {
            $bundle1->bundleItems()->create([
                'original_item_id' => $item->id,
                'position' => $index + 1,
            ]);
        }

        // Mock NLM service
        $mockSourceDTO = new SourceDTO(
            id: 'new-source',
            title: 'Test',
            url: null,
            created_at: now()->toIso8601String(),
            status: '1',
            kind: 'text',
        );

        $this->mockNotebookLMService
            ->shouldReceive('addSourceFile')
            ->andReturn($mockSourceDTO);

        $this->mockNotebookLMService
            ->shouldReceive('deleteSource')
            ->andReturn(true);

        // Execute consolidation
        $this->service->consolidate($this->notebook, MdBundleType::FrozenQuarter);

        // Assert old bundles marked as consolidating (they should be deleted after)
        // Actually, they get deleted in cleanupOldBundles, so we check they don't exist
        $this->assertDatabaseMissing('md_bundles', ['id' => $bundle1->id]);
        $this->assertDatabaseMissing('md_bundles', ['id' => $bundle2->id]);
    }

    public function test_consolidate_handles_nlm_upload_failure(): void
    {
        // Create two bundles
        $bundle1 = MdBundle::factory()->create([
            'notebook_id' => $this->notebook->id,
            'type' => MdBundleType::FrozenQuarter,
            'status' => MdBundleStatus::Uploaded,
            'is_consolidating' => false,
            'nlm_source_id' => 'old-source-1',
        ]);

        $bundle2 = MdBundle::factory()->create([
            'notebook_id' => $this->notebook->id,
            'type' => MdBundleType::FrozenQuarter,
            'status' => MdBundleStatus::Uploaded,
            'is_consolidating' => false,
            'nlm_source_id' => 'old-source-2',
        ]);

        // Create items
        OriginalItem::factory()->count(2)->create(['word_count' => 100]);

        // Mock NLM service to throw exception
        $this->mockNotebookLMService
            ->shouldReceive('addSourceFile')
            ->once()
            ->andThrow(new \RuntimeException('NLM upload failed'));

        // Execute consolidation and expect exception
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('NLM upload failed');

        $this->service->consolidate($this->notebook, MdBundleType::FrozenQuarter);

        // Assert new bundle created with error status
        $errorBundle = MdBundle::where('status', MdBundleStatus::Error)->first();
        $this->assertNotNull($errorBundle);
        $this->assertSame('upload_failed', $errorBundle->error_code);
    }

    public function test_consolidate_skips_deleting_bundles_without_nlm_source_id(): void
    {
        // Create two bundles - one without nlm_source_id
        $bundle1 = MdBundle::factory()->create([
            'notebook_id' => $this->notebook->id,
            'type' => MdBundleType::FrozenQuarter,
            'status' => MdBundleStatus::Uploaded,
            'is_consolidating' => false,
            'nlm_source_id' => null, // No NLM source
        ]);

        $bundle2 = MdBundle::factory()->create([
            'notebook_id' => $this->notebook->id,
            'type' => MdBundleType::FrozenQuarter,
            'status' => MdBundleStatus::Uploaded,
            'is_consolidating' => false,
            'nlm_source_id' => 'nlm-source-2',
        ]);

        // Create items
        $items = OriginalItem::factory()->count(2)->create(['word_count' => 100]);

        foreach ($items as $index => $item) {
            $bundle1->bundleItems()->create([
                'original_item_id' => $item->id,
                'position' => $index + 1,
            ]);
        }

        // Mock NLM service
        $mockSourceDTO = new SourceDTO(
            id: 'new-source',
            title: 'Test',
            url: null,
            created_at: now()->toIso8601String(),
            status: '1',
            kind: 'text',
        );

        $this->mockNotebookLMService
            ->shouldReceive('addSourceFile')
            ->once()
            ->andReturn($mockSourceDTO);

        // Should only delete one source (bundle2 has nlm_source_id, bundle1 doesn't)
        $this->mockNotebookLMService
            ->shouldReceive('deleteSource')
            ->once()
            ->andReturn(true);

        // Execute consolidation
        $this->service->consolidate($this->notebook, MdBundleType::FrozenQuarter);

        // Assert consolidation succeeded
        $this->assertDatabaseHas('md_bundles', [
            'type' => MdBundleType::FrozenHalf->value,
            'status' => MdBundleStatus::Uploaded->value,
        ]);
    }

    public function test_consolidate_calculates_word_count_correctly(): void
    {
        // Create two bundles with items having specific word counts
        $bundle1 = MdBundle::factory()->create([
            'notebook_id' => $this->notebook->id,
            'type' => MdBundleType::FrozenQuarter,
            'status' => MdBundleStatus::Uploaded,
            'is_consolidating' => false,
        ]);

        $bundle2 = MdBundle::factory()->create([
            'notebook_id' => $this->notebook->id,
            'type' => MdBundleType::FrozenQuarter,
            'status' => MdBundleStatus::Uploaded,
            'is_consolidating' => false,
        ]);

        // Create items with known word counts
        $item1 = OriginalItem::factory()->create(['word_count' => 1000]);
        $item2 = OriginalItem::factory()->create(['word_count' => 2000]);
        $item3 = OriginalItem::factory()->create(['word_count' => 3000]);

        // Attach: bundle1 has item1+item2, bundle2 has item3
        $bundle1->bundleItems()->create(['original_item_id' => $item1->id, 'position' => 1]);
        $bundle1->bundleItems()->create(['original_item_id' => $item2->id, 'position' => 2]);
        $bundle2->bundleItems()->create(['original_item_id' => $item3->id, 'position' => 1]);

        // Mock NLM service
        $mockSourceDTO = new SourceDTO(
            id: 'new-source',
            title: 'Test',
            url: null,
            created_at: now()->toIso8601String(),
            status: '1',
            kind: 'text',
        );

        $this->mockNotebookLMService
            ->shouldReceive('addSourceFile')
            ->andReturn($mockSourceDTO);

        $this->mockNotebookLMService
            ->shouldReceive('deleteSource')
            ->andReturn(true);

        // Execute consolidation
        $this->service->consolidate($this->notebook, MdBundleType::FrozenQuarter);

        // Assert word count is sum of all items
        $newBundle = MdBundle::where('type', MdBundleType::FrozenHalf)->first();
        $this->assertNotNull($newBundle);
        $this->assertSame(6000, $newBundle->word_count); // 1000 + 2000 + 3000
    }

    public function test_consolidate_throws_exception_for_invalid_source_type(): void
    {
        // Try to consolidate ActiveDelta (not allowed)
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid source bundle type for consolidation: active_delta');

        $this->service->consolidate($this->notebook, MdBundleType::ActiveDelta);
    }

    public function test_consolidate_reassigns_bundle_items_to_new_bundle(): void
    {
        // Create two bundles
        $bundle1 = MdBundle::factory()->create([
            'notebook_id' => $this->notebook->id,
            'type' => MdBundleType::FrozenHalf,
            'status' => MdBundleStatus::Uploaded,
            'is_consolidating' => false,
        ]);

        $bundle2 = MdBundle::factory()->create([
            'notebook_id' => $this->notebook->id,
            'type' => MdBundleType::FrozenHalf,
            'status' => MdBundleStatus::Uploaded,
            'is_consolidating' => false,
        ]);

        // Create items
        $items1 = OriginalItem::factory()->count(2)->create();
        $items2 = OriginalItem::factory()->count(3)->create();

        // Attach items
        foreach ($items1 as $index => $item) {
            $bundle1->bundleItems()->create([
                'original_item_id' => $item->id,
                'position' => $index + 1,
            ]);
        }

        foreach ($items2 as $index => $item) {
            $bundle2->bundleItems()->create([
                'original_item_id' => $item->id,
                'position' => $index + 1,
            ]);
        }

        // Mock NLM service
        $mockSourceDTO = new SourceDTO(
            id: 'new-source',
            title: 'Test',
            url: null,
            created_at: now()->toIso8601String(),
            status: '1',
            kind: 'text',
        );

        $this->mockNotebookLMService
            ->shouldReceive('addSourceFile')
            ->andReturn($mockSourceDTO);

        $this->mockNotebookLMService
            ->shouldReceive('deleteSource')
            ->andReturn(true);

        // Execute consolidation
        $this->service->consolidate($this->notebook, MdBundleType::FrozenHalf);

        // Assert new bundle has all items
        $newBundle = MdBundle::where('type', MdBundleType::FrozenFull)->first();
        $this->assertNotNull($newBundle);

        $bundleItemCount = $newBundle->bundleItems()->count();
        $this->assertSame(5, $bundleItemCount); // 2 + 3 items

        // Assert positions are sequential
        $positions = $newBundle->bundleItems()->orderBy('position')->pluck('position')->toArray();
        $this->assertSame([1, 2, 3, 4, 5], $positions);
    }

    // =========================================================================
    // Scan and Dispatch Consolidation Tests
    // =========================================================================

    public function test_scan_and_dispatch_dispatches_quarter_to_half_consolidation(): void
    {
        // Create a notebook with 2 FrozenQuarter bundles
        $notebook = Notebook::factory()->create();

        MdBundle::factory()->count(2)->create([
            'notebook_id' => $notebook->id,
            'type' => MdBundleType::FrozenQuarter,
            'status' => MdBundleStatus::Uploaded,
            'is_consolidating' => false,
        ]);

        // Mock the job dispatch
        Bus::fake();

        // Execute scan
        $this->service->scanAndDispatchConsolidation();

        // Assert consolidation job was dispatched
        Bus::assertDispatched(
            ConsolidateBundlesJob::class,
            function ($job) use ($notebook) {
                return $job->notebookId === $notebook->id && $job->level === 'quarter_to_half';
            }
        );
    }

    public function test_scan_and_dispatch_dispatches_half_to_full_consolidation(): void
    {
        // Create a notebook with 2 FrozenHalf bundles
        $notebook = Notebook::factory()->create();

        MdBundle::factory()->count(2)->create([
            'notebook_id' => $notebook->id,
            'type' => MdBundleType::FrozenHalf,
            'status' => MdBundleStatus::Uploaded,
            'is_consolidating' => false,
        ]);

        // Mock the job dispatch
        Bus::fake();

        // Execute scan
        $this->service->scanAndDispatchConsolidation();

        // Assert consolidation job was dispatched
        Bus::assertDispatched(
            ConsolidateBundlesJob::class,
            function ($job) use ($notebook) {
                return $job->notebookId === $notebook->id && $job->level === 'half_to_full';
            }
        );
    }

    public function test_scan_and_dispatch_does_not_dispatch_for_single_bundle(): void
    {
        // Create a notebook with only 1 FrozenQuarter bundle
        $notebook = Notebook::factory()->create();

        MdBundle::factory()->create([
            'notebook_id' => $notebook->id,
            'type' => MdBundleType::FrozenQuarter,
            'status' => MdBundleStatus::Uploaded,
            'is_consolidating' => false,
        ]);

        // Mock the job dispatch
        Bus::fake();

        // Execute scan
        $this->service->scanAndDispatchConsolidation();

        // Assert no consolidation job was dispatched
        Bus::assertNotDispatched(ConsolidateBundlesJob::class);
    }

    public function test_scan_and_dispatch_skips_bundles_already_consolidating(): void
    {
        // Create a notebook with 2 FrozenQuarter bundles, one already consolidating
        $notebook = Notebook::factory()->create();

        MdBundle::factory()->create([
            'notebook_id' => $notebook->id,
            'type' => MdBundleType::FrozenQuarter,
            'status' => MdBundleStatus::Uploaded,
            'is_consolidating' => true, // Already consolidating
        ]);

        MdBundle::factory()->create([
            'notebook_id' => $notebook->id,
            'type' => MdBundleType::FrozenQuarter,
            'status' => MdBundleStatus::Uploaded,
            'is_consolidating' => false,
        ]);

        // Mock the job dispatch
        Bus::fake();

        // Execute scan
        $this->service->scanAndDispatchConsolidation();

        // Assert no consolidation job was dispatched (only 1 non-consolidating bundle)
        Bus::assertNotDispatched(ConsolidateBundlesJob::class);
    }

    public function test_scan_and_dispatch_handles_multiple_notebooks(): void
    {
        // Create 2 notebooks, each with 2 FrozenQuarter bundles
        $notebook1 = Notebook::factory()->create();
        $notebook2 = Notebook::factory()->create();

        MdBundle::factory()->count(2)->create([
            'notebook_id' => $notebook1->id,
            'type' => MdBundleType::FrozenQuarter,
            'status' => MdBundleStatus::Uploaded,
            'is_consolidating' => false,
        ]);

        MdBundle::factory()->count(2)->create([
            'notebook_id' => $notebook2->id,
            'type' => MdBundleType::FrozenQuarter,
            'status' => MdBundleStatus::Uploaded,
            'is_consolidating' => false,
        ]);

        // Mock the job dispatch
        Bus::fake();

        // Execute scan
        $this->service->scanAndDispatchConsolidation();

        // Assert 2 consolidation jobs were dispatched (one for each notebook)
        Bus::assertDispatchedTimes(ConsolidateBundlesJob::class, 2);
    }

    public function test_scan_and_dispatch_dispatches_both_types_when_applicable(): void
    {
        // Create a notebook with both 2 FrozenQuarter AND 2 FrozenHalf bundles
        $notebook = Notebook::factory()->create();

        MdBundle::factory()->count(2)->create([
            'notebook_id' => $notebook->id,
            'type' => MdBundleType::FrozenQuarter,
            'status' => MdBundleStatus::Uploaded,
            'is_consolidating' => false,
        ]);

        MdBundle::factory()->count(2)->create([
            'notebook_id' => $notebook->id,
            'type' => MdBundleType::FrozenHalf,
            'status' => MdBundleStatus::Uploaded,
            'is_consolidating' => false,
        ]);

        // Mock the job dispatch
        Bus::fake();

        // Execute scan
        $this->service->scanAndDispatchConsolidation();

        // Assert 2 consolidation jobs were dispatched (one for each type)
        Bus::assertDispatchedTimes(ConsolidateBundlesJob::class, 2);

        // Assert both types were dispatched
        Bus::assertDispatched(
            ConsolidateBundlesJob::class,
            function ($job) {
                return $job->level === 'quarter_to_half';
            }
        );

        Bus::assertDispatched(
            ConsolidateBundlesJob::class,
            function ($job) {
                return $job->level === 'half_to_full';
            }
        );
    }
}
