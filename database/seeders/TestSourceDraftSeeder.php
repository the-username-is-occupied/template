<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\SourceDraftStatus;
use App\Enums\SourceType;
use App\Models\Notebook;
use App\Models\TechAccount;
use App\Models\User;
use App\Services\NotebookService;
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

        // $notebook = Notebook::find('019f64da-8b4f-71c9-9e94-f6759b4b8a5c');

        $notebook = app()->make(NotebookService::class)->create(
            'Программа "Статус"',
            $techAccount,
            $user
        );

        $this->command->info("✓ Created notebook: {$notebook->title} (ID: {$notebook->id})");

        // 5. Create SourceDraft
        $rawInput = 'https://www.youtube.com/watch?v=83oEvYsN9ZU&list=PLYdsjx7Rg7k5aFd5fpHdxQO_5kUc9Y5ex';

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
            'limit' => 4000,
            'chunk_limit' => 1000,
            'workers' => 3,
        ];

        if ($draft->type === SourceType::YoutubeChannel) {
            $draft->fetchUrls();
        }

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
