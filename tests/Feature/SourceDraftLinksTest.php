<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ReviewStatus;
use App\Enums\SourceType;
use App\Models\ContentSource;
use App\Models\Notebook;
use App\Models\OriginalItem;
use App\Models\SourceDraft;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class SourceDraftLinksTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Notebook $notebook;

    private SourceDraft $draft;

    private ContentSource $parentSource;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->notebook = Notebook::factory()->create(['user_id' => $this->user->id]);

        $this->parentSource = ContentSource::factory()->create([
            'user_id' => $this->user->id,
        ]);

        $this->draft = SourceDraft::factory()->create([
            'user_id' => $this->user->id,
            'knowledge_base_id' => $this->notebook->id,
            'content_source_id' => $this->parentSource->id,
        ]);
    }

    public function test_can_get_discovered_links(): void
    {
        // Create pending review sources
        $item1 = OriginalItem::factory()->create(['content_source_id' => $this->parentSource->id]);
        $item2 = OriginalItem::factory()->create(['content_source_id' => $this->parentSource->id]);

        ContentSource::factory()->create([
            'user_id' => $this->user->id,
            'parent_source_id' => $this->parentSource->id,
            'parent_item_id' => $item1->id,
            'review_status' => ReviewStatus::PendingReview,
            'type' => SourceType::TelegramChannel,
            'url' => 'https://t.me/channel1/123',
        ]);

        ContentSource::factory()->create([
            'user_id' => $this->user->id,
            'parent_source_id' => $this->parentSource->id,
            'parent_item_id' => $item2->id,
            'review_status' => ReviewStatus::PendingReview,
            'type' => SourceType::Website,
            'url' => 'https://example.com/article',
        ]);

        $response = $this->actingAs($this->user)
            ->getJson("/api/source-drafts/{$this->draft->id}/links");

        $response->assertStatus(200);
        $response->assertJsonCount(2); // Two groups
        $response->assertJsonStructure([
            '*' => ['domain', 'type', 'count', 'items'],
        ]);
    }

    public function test_links_are_grouped_by_channel(): void
    {
        $item = OriginalItem::factory()->create(['content_source_id' => $this->parentSource->id]);

        // Create two TG links from same channel
        ContentSource::factory()->create([
            'user_id' => $this->user->id,
            'parent_source_id' => $this->parentSource->id,
            'parent_item_id' => $item->id,
            'review_status' => ReviewStatus::PendingReview,
            'type' => SourceType::TelegramChannel,
            'url' => 'https://t.me/channel1/123',
        ]);

        ContentSource::factory()->create([
            'user_id' => $this->user->id,
            'parent_source_id' => $this->parentSource->id,
            'parent_item_id' => $item->id,
            'review_status' => ReviewStatus::PendingReview,
            'type' => SourceType::TelegramChannel,
            'url' => 'https://t.me/channel1/456',
        ]);

        $response = $this->actingAs($this->user)
            ->getJson("/api/source-drafts/{$this->draft->id}/links");

        $response->assertStatus(200);
        $response->assertJsonCount(1); // Should be grouped into 1
        $response->assertJsonPath('0.count', 2);
    }

    public function test_includes_source_post_url(): void
    {
        $item = OriginalItem::factory()->create([
            'content_source_id' => $this->parentSource->id,
            'source_url' => 'https://t.me/parent/999',
        ]);

        $childSource = ContentSource::factory()->create([
            'user_id' => $this->user->id,
            'parent_source_id' => $this->parentSource->id,
            'parent_item_id' => $item->id,
            'review_status' => ReviewStatus::PendingReview,
            'type' => SourceType::TelegramChannel,
            'url' => 'https://t.me/channel1/123',
        ]);

        $response = $this->actingAs($this->user)
            ->getJson("/api/source-drafts/{$this->draft->id}/links");

        $response->assertStatus(200);
        $response->assertJsonPath('0.items.0.source_post_url', 'https://t.me/parent/999');
    }

    public function test_cannot_access_other_users_draft(): void
    {
        $otherUser = User::factory()->create();
        $otherDraft = SourceDraft::factory()->create([
            'user_id' => $otherUser->id,
        ]);

        $response = $this->actingAs($this->user)
            ->getJson("/api/source-drafts/{$otherDraft->id}/links");

        $response->assertStatus(403);
    }
}
