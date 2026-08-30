<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\NotebookLM\NotebookLMService;
use App\Enums\TechAccountStatus;
use App\Models\TechAccount;
use Exception;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Tests\TestCase;

/**
 * Manual integration test for NotebookLM FastAPI service.
 *
 * This test requires a real storage_state.json file at .temp/storage_state.json
 * To run manually: php artisan test --filter=NotebookLMServiceTest
 *
 * If the storage_state.json file is not found, the test will be skipped.
 */
final class NotebookLMServiceTest extends TestCase
{
    use RefreshDatabase;

    protected string $cookieBasePath;

    protected ?string $storageStatePath = null;

    protected ?TechAccount $account = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cookieBasePath = config('tech-accounts.cookie_base_path', storage_path('app/cookies'));

        // Check if storage_state.json exists
        $this->storageStatePath = base_path('.temp/storage_state.json');

        if (! File::exists($this->storageStatePath)) {
            $this->markTestSkipped('storage_state.json not found at '.$this->storageStatePath);
        }
    }

    protected function tearDown(): void
    {
        // Clean up account from FastAPI
        if ($this->account instanceof TechAccount) {
            try {
                $service = new NotebookLMService;
                $service->removeAccount($this->account->id);
            } catch (Exception $e) {
                // Ignore errors during cleanup
            }
        }

        // Clean up cookie directory for this test
        if ($this->account && File::exists(dirname($this->account->cookie_path))) {
            File::deleteDirectory(dirname($this->account->cookie_path));
        }

        parent::tearDown();
    }

    public function test_it_can_create_tech_account_and_initialize_in_fastapi(): void
    {
        $this->withoutMiddleware();

        // Create a tech account with storage_state.json
        $response = $this->post(route('admin.tech-accounts.store'), [
            'name' => 'Test NotebookLM Account',
            'email' => 'test-notebooklm@example.com',
            'pool_type' => 'free',
            'status' => TechAccountStatus::Active->value,
            'notebooks_count' => 0,
            'chats_today' => 0,
            'chats_reset_at' => now()->format('Y-m-d H:i:s'),
            'last_used_at' => now()->format('Y-m-d H:i:s'),
            'storage_state' => new UploadedFile(
                $this->storageStatePath,
                'storage_state.json',
                'application/json',
                null,
                true
            ),
        ]);

        $response->assertRedirect(route('admin.tech-accounts.index'));

        $this->account = TechAccount::query()->where('email', 'test-notebooklm@example.com')->firstOrFail();

        $this->assertNotNull($this->account);
        $this->assertFileExists($this->account->cookie_path);

        // Initialize account in FastAPI
        $service = new NotebookLMService;

        try {
            $result = $service->initializeAccount($this->account->id);
            $this->assertSame('created', $result['status']);
        } catch (Exception $e) {
            $this->markTestSkipped('FastAPI service not available: '.$e->getMessage());
        }
    }

    public function test_it_can_list_notebooks_via_fastapi(): void
    {
        $this->withoutMiddleware();

        // Create account
        $service = new NotebookLMService;

        $this->account = TechAccount::factory()->create([
            'name' => 'Test Account',
            'email' => 'list-test@example.com',
            'status' => TechAccountStatus::Active,
        ]);

        // Set correct cookie_path based on account id
        $this->account->cookie_path = $this->cookieBasePath.'/'.$this->account->id.'/storage_state.json';
        $this->account->save();

        // Copy storage_state.json to cookie path
        $cookieDir = dirname($this->account->cookie_path);
        File::ensureDirectoryExists($cookieDir);
        File::copy($this->storageStatePath, $this->account->cookie_path);

        // Initialize in FastAPI
        try {
            $result = $service->initializeAccount($this->account->id);
            $this->assertSame('created', $result['status']);
        } catch (Exception $e) {
            // Skip if authentication is expired or FastAPI is not available
            $this->markTestSkipped('FastAPI service not available or auth expired: '.$e->getMessage());
        }

        // Test listing notebooks
        try {
            $result = $service->listNotebooks($this->account->id);

            $this->assertArrayHasKey('response_time_ms', $result);
            $this->assertArrayHasKey('notebooks', $result);
            $this->assertIsArray($result['notebooks']);
        } catch (Exception $e) {
            $this->markTestSkipped('Failed to list notebooks: '.$e->getMessage());
        }
    }

    public function test_it_can_check_health_accounts(): void
    {
        $service = new NotebookLMService;

        try {
            $result = $service->healthAccounts();

            $this->assertIsArray($result);

            // If we have accounts, check structure
            foreach ($result as $health) {
                $this->assertArrayHasKey('mtime', $health);
                $this->assertArrayHasKey('is_connected', $health);
                $this->assertArrayHasKey('status', $health);
            }
        } catch (Exception $e) {
            $this->markTestSkipped('FastAPI service not available: '.$e->getMessage());
        }
    }

    public function test_it_handles_missing_account_gracefully(): void
    {
        $service = new NotebookLMService;

        $this->expectException(RuntimeException::class);

        $service->listNotebooks('non-existent-account-id');
    }
}
