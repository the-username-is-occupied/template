<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Domain\NotebookLM\DTOs\NotebookDTO;
use App\Domain\NotebookLM\NotebookLMService;
use App\Models\TechAccount;
use App\Models\TechNotebook;
use App\Services\TechNotebookPoolService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class TechNotebookPoolServiceTest extends TestCase
{
    use RefreshDatabase;

    private TechNotebookPoolService $service;

    private \PHPUnit\Framework\MockObject\MockObject|NotebookLMService $mockNotebookLMService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mockNotebookLMService = $this->createMock(NotebookLMService::class);
        $this->service = new TechNotebookPoolService;
    }

    /**
     * Helper to create a NotebookDTO from array.
     */
    private function createNotebookDTO(array $data): NotebookDTO
    {
        return NotebookDTO::from([
            'id' => $data['id'],
            'title' => $data['title'],
            'sources_count' => $data['sources_count'] ?? 0,
        ]);
    }

    /**
     * Test that maintain creates missing notebooks when pool is under target.
     */
    public function test_maintain_creates_missing_notebooks_when_pool_under_target(): void
    {
        // Create an account
        $account = TechAccount::create([
            'name' => 'Test Account',
            'email' => 'test@example.com',
            'pool_type' => 'free',
            'status' => 'active',
            'cookie_path' => '/tmp/test_cookie',
            'notebooks_count' => 0,
        ]);

        // Create NotebookDTO using from() method
        $notebookDTO = $this->createNotebookDTO([
            'id' => 'test-notebook-id',
            'title' => 'Tech Notebook - source_extractor - 1234567890',
            'sources_count' => 0,
        ]);

        // Expect 7 calls total (5 source_extractor + 1 summary_aggregator + 1 global_search)
        $this->mockNotebookLMService
            ->expects($this->exactly(7))
            ->method('createNotebook')
            ->willReturn($notebookDTO);

        // Execute maintain
        $this->service->maintain($this->mockNotebookLMService);

        // Assert 7 notebooks were created (5 source_extractor + 1 summary_aggregator + 1 global_search)
        $this->assertDatabaseCount('tech_notebooks', 7);
        $this->assertDatabaseHas('tech_notebooks', [
            'type' => 'source_extractor',
            'status' => 'active',
            'account_id' => $account->id,
        ]);

        // Assert account notebooks_count was incremented
        $this->assertEquals(7, $account->fresh()->notebooks_count);
    }

    /**
     * Test that maintain handles degraded notebooks by recreating them.
     */
    public function test_maintain_recreates_degraded_notebooks(): void
    {
        // Create an account
        $account = TechAccount::create([
            'name' => 'Test Account',
            'email' => 'test@example.com',
            'pool_type' => 'free',
            'status' => 'active',
            'cookie_path' => '/tmp/test_cookie',
            'notebooks_count' => 0,
        ]);

        // Create a degraded notebook (use notebook_id column)
        $degradedNotebook = TechNotebook::create([
            'account_id' => $account->id,
            'notebook_id' => 'old-notebook-id',
            'type' => 'source_extractor',
            'status' => 'degraded',
        ]);

        // Create new NotebookDTO
        $newNotebookDTO = $this->createNotebookDTO([
            'id' => 'new-notebook-id',
            'title' => 'Tech Notebook - source_extractor - 1234567890',
            'sources_count' => 0,
        ]);

        // Expect 8 calls total:
        // - 5 for source_extractor (to reach target of 5, degraded doesn't count as active)
        // - 1 for summary_aggregator
        // - 1 for global_search
        // - 1 to recreate the degraded notebook
        $this->mockNotebookLMService
            ->expects($this->exactly(8))
            ->method('createNotebook')
            ->willReturn($newNotebookDTO);

        // Execute maintain
        $this->service->maintain($this->mockNotebookLMService);

        // Assert degraded notebook was updated with new ID and status
        $degradedNotebook->refresh();
        $this->assertEquals('new-notebook-id', $degradedNotebook->notebook_id);
        $this->assertEquals('active', $degradedNotebook->status);

        // Assert total count is 8:
        // - 5 source_extractor (created to reach target)
        // - 1 summary_aggregator
        // - 1 global_search
        // - 1 updated degraded (not deleted, just updated)
        $this->assertDatabaseCount('tech_notebooks', 8);
    }

    /**
     * Test that maintain skips creation when pool is at target.
     */
    public function test_maintain_skips_creation_when_pool_at_target(): void
    {
        // Create an account
        $account = TechAccount::create([
            'name' => 'Test Account',
            'email' => 'test@example.com',
            'pool_type' => 'free',
            'status' => 'active',
            'cookie_path' => '/tmp/test_cookie',
            'notebooks_count' => 7,
        ]);

        // Create 5 active notebooks (target for source_extractor) - use notebook_id column
        for ($i = 0; $i < 5; $i++) {
            TechNotebook::create([
                'account_id' => $account->id,
                'notebook_id' => "notebook-id-{$i}",
                'type' => 'source_extractor',
                'status' => 'active',
            ]);
        }

        // Also create 1 summary_aggregator and 1 global_search to meet all targets
        TechNotebook::create([
            'account_id' => $account->id,
            'notebook_id' => 'notebook-id-summary',
            'type' => 'summary_aggregator',
            'status' => 'active',
        ]);

        TechNotebook::create([
            'account_id' => $account->id,
            'notebook_id' => 'notebook-id-global',
            'type' => 'global_search',
            'status' => 'active',
        ]);

        // Mock NotebookLMService - should not be called
        $this->mockNotebookLMService
            ->expects($this->never())
            ->method('createNotebook');

        // Execute maintain
        $this->service->maintain($this->mockNotebookLMService);

        // Assert no new notebooks were created (7 = 5 + 1 + 1)
        $this->assertDatabaseCount('tech_notebooks', 7);
    }

    /**
     * Test that maintain handles missing accounts gracefully.
     */
    public function test_maintain_handles_missing_accounts(): void
    {
        // No accounts exist

        // Mock NotebookLMService - should not be called
        $this->mockNotebookLMService
            ->expects($this->never())
            ->method('createNotebook');

        // Execute maintain - should not throw exception
        $this->service->maintain($this->mockNotebookLMService);

        // Assert no notebooks were created
        $this->assertDatabaseCount('tech_notebooks', 0);
    }

    /**
     * Test that maintain creates notebooks for all target types.
     */
    public function test_maintain_creates_notebooks_for_all_target_types(): void
    {
        // Create an account
        $account = TechAccount::create([
            'name' => 'Test Account',
            'email' => 'test@example.com',
            'pool_type' => 'free',
            'status' => 'active',
            'cookie_path' => '/tmp/test_cookie',
            'notebooks_count' => 0,
        ]);

        // Create NotebookDTOs for all types
        $sourceExtractorDTO = $this->createNotebookDTO([
            'id' => 'source-extractor-id',
            'title' => 'Tech Notebook - source_extractor',
            'sources_count' => 0,
        ]);

        $summaryAggregatorDTO = $this->createNotebookDTO([
            'id' => 'summary-aggregator-id',
            'title' => 'Tech Notebook - summary_aggregator',
            'sources_count' => 0,
        ]);

        $globalSearchDTO = $this->createNotebookDTO([
            'id' => 'global-search-id',
            'title' => 'Tech Notebook - global_search',
            'sources_count' => 0,
        ]);

        $this->mockNotebookLMService
            ->expects($this->exactly(7)) // 5 + 1 + 1
            ->method('createNotebook')
            ->willReturnOnConsecutiveCalls(
                // 5 for source_extractor
                $sourceExtractorDTO,
                $sourceExtractorDTO,
                $sourceExtractorDTO,
                $sourceExtractorDTO,
                $sourceExtractorDTO,
                // 1 for summary_aggregator
                $summaryAggregatorDTO,
                // 1 for global_search
                $globalSearchDTO
            );

        // Execute maintain
        $this->service->maintain($this->mockNotebookLMService);

        // Assert notebooks were created for all types
        $this->assertDatabaseCount('tech_notebooks', 7);
        $this->assertDatabaseHas('tech_notebooks', [
            'type' => 'source_extractor',
            'status' => 'active',
        ]);
        $this->assertDatabaseHas('tech_notebooks', [
            'type' => 'summary_aggregator',
            'status' => 'active',
        ]);
        $this->assertDatabaseHas('tech_notebooks', [
            'type' => 'global_search',
            'status' => 'active',
        ]);
    }

    /**
     * Test that maintain distributes notebooks across multiple accounts.
     */
    public function test_maintain_distributes_notebooks_across_accounts(): void
    {
        // Create two accounts
        $account1 = TechAccount::create([
            'name' => 'Test Account 1',
            'email' => 'test1@example.com',
            'pool_type' => 'free',
            'status' => 'active',
            'cookie_path' => '/tmp/test_cookie_1',
            'notebooks_count' => 0,
        ]);

        $account2 = TechAccount::create([
            'name' => 'Test Account 2',
            'email' => 'test2@example.com',
            'pool_type' => 'free',
            'status' => 'active',
            'cookie_path' => '/tmp/test_cookie_2',
            'notebooks_count' => 0,
        ]);

        // Create NotebookDTO
        $notebookDTO = $this->createNotebookDTO([
            'id' => 'test-notebook-id',
            'title' => 'Tech Notebook',
            'sources_count' => 0,
        ]);

        // Expect 7 calls total (5 source_extractor + 1 summary_aggregator + 1 global_search)
        $this->mockNotebookLMService
            ->expects($this->exactly(7))
            ->method('createNotebook')
            ->willReturn($notebookDTO);

        // Execute maintain
        $this->service->maintain($this->mockNotebookLMService);

        // Assert notebooks were distributed (account with least notebooks should be selected each time)
        $account1Refresh = $account1->fresh();
        $account2Refresh = $account2->fresh();

        // Total should be 7
        $this->assertEquals(7, $account1Refresh->notebooks_count + $account2Refresh->notebooks_count);
    }
}
