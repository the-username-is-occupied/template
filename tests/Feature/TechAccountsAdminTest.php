<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\TechAccounts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

final class TechAccountsAdminTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_renders_the_tech_accounts_admin_page(): void
    {
        $response = $this->get(route('admin.tech-accounts.index'));

        $response->assertOk();
        $response->assertSee('No accounts yet.');
    }

    public function test_it_stores_a_tech_account_and_persists_the_storage_state_file(): void
    {
        $this->withoutMiddleware();

        $cookieBasePath = storage_path('framework/testing-tech-accounts');
        config()->set('tech-accounts.cookie_base_path', $cookieBasePath);

        $response = $this->post(route('admin.tech-accounts.store'), [
            'name' => 'Account One',
            'email' => 'account-one@example.com',
            'pool_type' => 'free',
            'status' => 'active',
            'notebooks_count' => 3,
            'chats_today' => 7,
            'chats_reset_at' => now()->format('Y-m-d H:i:s'),
            'last_used_at' => now()->format('Y-m-d H:i:s'),
            'storage_state' => UploadedFile::fake()->createWithContent('storage_state.json', '{"cookies":[] }'),
        ]);

        $response->assertRedirect(route('admin.tech-accounts.index'));

        $account = TechAccounts::query()->where('email', 'account-one@example.com')->firstOrFail();

        $this->assertSame('Account One', $account->name);
        $this->assertSame('active', $account->status->value);
        $this->assertSame('free', $account->pool_type->value);
        $this->assertSame($cookieBasePath.'/'.$account->id.'/storage_state.json', $account->cookie_path);
        $this->assertFileExists($account->cookie_path);

        $contents = File::get($account->cookie_path);
        $this->assertJson($contents);
    }

    // public function test_it_updates_a_tech_account_without_reuploading_storage_state(): void
    // {
    //     $this->withoutMiddleware();

    //     $cookieBasePath = storage_path('framework/testing-tech-accounts');
    //     config()->set('tech-accounts.cookie_base_path', $cookieBasePath);

    //     $account = TechAccounts::factory()->create([
    //         'name' => 'Original Name',
    //         'email' => 'original@example.com',
    //         'pool_type' => 'free',
    //         'status' => 'active',
    //     ]);

    //     $id = $account->id;

    //     $response = $this->put(route('admin.tech-accounts.update', ['techAccount' => $account->id]), [
    //         'name' => 'Updated Name',
    //         'email' => 'origina232l@example.com',
    //         'pool_type' => 'free',
    //         'status' => 'active',
    //         'notebooks_count' => 8,
    //         'chats_today' => 11,
    //         'chats_reset_at' => now()->format('Y-m-d H:i:s'),
    //         'last_used_at' => now()->format('Y-m-d H:i:s'),
    //     ]);

    //     $response->assertRedirect(route('admin.tech-accounts.index'));

    //     $account->fresh();

    //     $this->assertSame($id, $account->id);
    //     $this->assertSame('Updated Name', $account->name);
    //     $this->assertSame('original@example.com', $account->email);
    //     $this->assertSame($cookieBasePath.'/'.$account->id.'/storage_state.json', $account->cookie_path);
    // }
}
