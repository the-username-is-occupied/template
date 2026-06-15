<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ReviewStatus;
use App\Models\ContentSource;
use App\Models\MdBundle;
use App\Models\Notebook;
use App\Models\OriginalItem;
use App\Models\SourceDraft;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class SourcePipelineModelsTest extends TestCase
{
    use RefreshDatabase;

    public function test_content_source_scopes_and_relations(): void
    {
        $user = User::factory()->create();

        $approvedSource = ContentSource::factory()->create([
            'user_id' => $user->id,
            'review_status' => ReviewStatus::Approved,
            'auto_update' => true,
        ]);

        $pendingSource = ContentSource::factory()->autoExtracted()->pendingReview()->create([
            'user_id' => $user->id,
        ]);

        $this->assertSame(1, ContentSource::pendingReview()->count());
        $this->assertSame(1, ContentSource::autoUpdate()->count());
        $this->assertSame(1, ContentSource::approved()->count());
        $this->assertSame($user->id, $approvedSource->user->id);
        $this->assertNull($pendingSource->parent_source_id);
    }

    public function test_original_item_unbundled_scope(): void
    {
        $item = OriginalItem::factory()->create();
        $bundled = OriginalItem::factory()->create(['md_bundle_id' => MdBundle::factory()]);

        $this->assertTrue($item->is($item->fresh()));
        $this->assertSame(1, OriginalItem::unbundled()->count());
        $this->assertSame(1, OriginalItem::whereNotNull('md_bundle_id')->count());
    }

    public function test_md_bundle_scopes_and_relations(): void
    {
        $notebook = Notebook::factory()->create();
        $bundle = MdBundle::factory()->activeDelta()->create(['notebook_id' => $notebook->id]);

        $this->assertSame(1, MdBundle::active()->count());
        $this->assertSame(1, MdBundle::activeDelta()->count());
        $this->assertSame($notebook->id, $bundle->notebook->id);
        $bundle->is_consolidating = true;
        $bundle->save();
        $this->assertSame(1, MdBundle::consolidating()->count());
    }

    public function test_source_draft_relations(): void
    {
        $user = User::factory()->create();
        $notebook = Notebook::factory()->create(['user_id' => $user->id]);
        $contentSource = ContentSource::factory()->create(['user_id' => $user->id]);

        $draft = SourceDraft::factory()->create([
            'user_id' => $user->id,
            'knowledge_base_id' => $notebook->id,
            'content_source_id' => $contentSource->id,
            'status' => 'awaiting_confirm',
        ]);

        $this->assertSame($user->id, $draft->user->id);
        $this->assertSame($notebook->id, $draft->knowledgeBase->id);
        $this->assertSame($contentSource->id, $draft->contentSource->id);
    }
}
