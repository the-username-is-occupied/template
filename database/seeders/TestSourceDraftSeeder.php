<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\NotebookLM\NotebookLMService;
use App\Enums\SourceDraftStatus;
use App\Models\Notebook;
use App\Models\TechAccount;
use App\Models\User;
use App\Services\SourceDraftService;
use App\Services\SourceService;
use Illuminate\Database\Seeder;

class TestSourceDraftSeeder extends Seeder
{
    public function run(): void
    {
        $this->command->info('🚀 Starting TestSourceDraftSeeder...');

        // 1. Create a test user
        $user = User::first();

        $this->command->info("✓ Created user: {$user->name} (ID: {$user->id})");

        // 2. Create or get a TechAccount (required for Notebook)
        $techAccount = TechAccount::first();

        $this->command->info("✓ Using TechAccount: {$techAccount->name} (ID: {$techAccount->id})");

        // $notebook = $techAccount->notebooks()->first();

        // 3. Create a notebook in NLM first
        $notebookLMService = app(NotebookLMService::class);

        $this->command->info('⏳ Creating notebook in NLM...');
        $notebookDTO = $notebookLMService->createNotebook(
            $techAccount->id,
            'bchlaw Channel'
        );
        $nlmNotebookId = $notebookDTO->id;

        $this->command->info("✓ Created NLM notebook (ID: {$nlmNotebookId})");

        // 4. Create a notebook for the user (with NLM notebook ID)
        // $notebook = Notebook::find('019f0e89-a41b-7357-a61c-d8d82a655cdb');

        $notebook = Notebook::create([
            'user_id' => $user->id,
            'tech_account_id' => $techAccount->id,
            'nlm_notebook_id' => $nlmNotebookId,
            'title' => 'bchlaw Channel',
        ]);

        $this->command->info("✓ Created notebook: {$notebook->title} (ID: {$notebook->id})");

        // 5. Create SourceDraft for tolk_tolk channel
        $rawInput = 'https://t.me/bchlaw';

        $draftService = app(SourceDraftService::class);
        $drafts = $draftService->create($user, $notebook, $rawInput);

        $draft = $drafts->first();

        $this->command->info("✓ Created SourceDraft (ID: {$draft->id})");
        $this->command->line("  - Type: {$draft->type->value}");
        $this->command->line("  - Status: {$draft->status->value}");
        $this->command->line("  - Raw input: {$draft->raw_input}");

        // 6. Wait for meta fetching to complete
        $this->command->info('⏳ Waiting for meta fetching...');

        $maxWaitTime = 30; // seconds
        $waitInterval = 2; // seconds
        $elapsed = 0;

        while ($elapsed < $maxWaitTime) {
            $draft->refresh();

            if ($draft->status === SourceDraftStatus::AwaitingConfirm) {
                $this->command->info('✓ Meta fetched successfully!');
                break;
            }

            if ($draft->status === SourceDraftStatus::Abandoned) {
                $this->command->error('✗ Meta fetching failed. Draft abandoned.');
                $this->command->error('Draft data: '.json_encode($draft->toArray(), JSON_PRETTY_PRINT));

                return;
            }

            $this->command->info('Wait');
            sleep($waitInterval);
            $elapsed += $waitInterval;
        }

        if ($draft->status !== SourceDraftStatus::AwaitingConfirm) {
            $this->command->warn("⚠ Timeout waiting for meta. Current status: {$draft->status->value}");
        }

        // 7. Display channel meta
        if ($draft->channel_meta) {
            $this->command->info('📊 Channel Meta:');
            $this->command->line('  - Title: '.($draft->channel_meta['title'] ?? 'N/A'));
            $this->command->line('  - Description: '.substr($draft->channel_meta['description'] ?? 'N/A', 0, 100).'...');
            $this->command->line('  - Members: '.($draft->channel_meta['members'] ?? 'N/A'));
        }

        $scrapeConfig = [
            'limit' => 200,
            'chunk_limit' => 100,
            'workers' => 2,
        ];

        $sourceService = app(SourceService::class);
        $source = $sourceService->confirmAndProcess($draft, $scrapeConfig);

        $this->command->info('✓ Draft confirmed and processing started!');
        $this->command->line("  - ContentSource ID: {$source->id}");
        $this->command->line('  - Source status: '.$draft->fresh()->status->value);

        // 9. Final summary
        $this->command->info(str_repeat('=', 50));
        $this->command->info('📋 SUMMARY');
        $this->command->info(str_repeat('=', 50));
        $this->command->line("User: {$user->name} (ID: {$user->id})");
        $this->command->line("Notebook: {$notebook->title} (ID: {$notebook->id})");
        $this->command->line("SourceDraft: ID {$draft->id}");
        $this->command->line('  - Type: '.$draft->type->value);
        $this->command->line('  - Status: '.$draft->fresh()->status->value);
        $this->command->line("  - Channel: {$draft->raw_input}");
        $this->command->line("ContentSource: ID {$source->id}");
        $this->command->line('  - Extraction status: '.$source->extraction_status->value);
        $this->command->info(str_repeat('=', 50));

        $this->command->info('✅ Test completed successfully!');
        $this->command->line('You can now check the SourceDraft and ContentSource in the database.');
        $this->command->line("'SourceDraft::find('{$draft->id}')'");
    }
}
