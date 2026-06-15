<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\BundleItem;
use App\Models\ContentSource;
use App\Models\MdBundle;
use App\Models\Notebook;
use App\Models\OriginalItem;
use App\Models\SourceDraft;
use App\Models\User;
use Illuminate\Database\Seeder;

class SourcePipelineSeeder extends Seeder
{
    public function run(): void
    {
        $user = User::factory()->create();

        $notebook = Notebook::factory()->create([
            'user_id' => $user->id,
            'nlm_notebook_id' => 'demo-notebook-id',
            'title' => 'Demo Notebook',
            'system_prompt' => 'Demo source pipeline notebook.',
        ]);

        $contentSource = ContentSource::factory()
            ->telegram()
            ->state([
                'user_id' => $user->id,
                'auto_update' => true,
                'extraction_status' => 'extracted',
            ])
            ->create();

        $originalItems = OriginalItem::factory()->count(3)->state([
            'content_source_id' => $contentSource->id,
        ])->create();

        $bundle = MdBundle::factory()
            ->activeDelta()
            ->state([
                'notebook_id' => $notebook->id,
                'status' => 'uploaded',
                'nlm_source_id' => 'nlm-demo-source',
                'file_path' => 'bundles/'.$notebook->id.'/active_delta.md',
            ])
            ->create();

        foreach ($originalItems as $index => $item) {
            BundleItem::factory()->create([
                'bundle_id' => $bundle->id,
                'original_item_id' => $item->id,
                'position' => $index + 1,
            ]);

            $item->update(['md_bundle_id' => $bundle->id]);
        }

        SourceDraft::factory()->create([
            'user_id' => $user->id,
            'knowledge_base_id' => $notebook->id,
            'content_source_id' => $contentSource->id,
            'type' => 'telegram_channel',
            'raw_input' => 'https://t.me/'.fake()->userName(),
            'channel_meta' => [
                'title' => 'Demo Telegram Channel',
                'description' => 'Demo description',
                'members' => '12345',
                'avatar_url' => fake()->imageUrl(),
            ],
            'scrape_config' => ['limit' => 50],
            'auto_update' => true,
            'status' => 'done',
        ]);
    }
}
