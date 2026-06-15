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
        $this->notebook = Notebook::factory()->forUser($this->user)->create();

        $this->parentSource = ContentSource::factory()->for($this->user)->create();

        $this->draft = SourceDraft::factory()
            ->forUser($this->user)
            ->forNotebook($this->notebook)
            ->withContentSource($this->parentSource)
            ->create();
    }

    public function test_can_get_discovered_links(): void
    {
        // Create pending review sources
        $item1 = OriginalItem::factory()->create(['content_source_id' => $this->parentSource->id]);
        $item2 = OriginalItem::factory()->create(['content_source_id' => $this->parentSource->id]);

        ContentSource::factory()
            ->for($this->user)
            ->withParent($this->parentSource, $item1)
            ->pendingReview()
            ->withType(SourceType::TelegramChannel)
            ->withTelegramUrl('channel1')
            ->create();

        ContentSource::factory()
            ->for($this->user)
            ->withParent($this->parentSource, $item2)
            ->pendingReview()
            ->withType(SourceType::Website)
            ->withUrl('https://example.com/article')
            ->create();

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
        ContentSource::factory()
            ->for($this->user)
            ->withParent($this->parentSource, $item)
            ->pendingReview()
            ->withType(SourceType::TelegramChannel)
            ->withTelegramUrl('channel1')
            ->create();

        ContentSource::factory()
            ->for($this->user)
            ->withParent($this->parentSource, $item)
            ->pendingReview()
            ->withType(SourceType::TelegramChannel)
            ->withTelegramUrl('channel1')
            ->create();

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

        ContentSource::factory()
            ->for($this->user)
            ->withParent($this->parentSource, $item)
            ->pendingReview()
            ->withType(SourceType::TelegramChannel)
            ->withTelegramUrl('channel1')
            ->create();

        $response = $this->actingAs($this->user)
            ->getJson("/api/source-drafts/{$this->draft->id}/links");

        $response->assertStatus(200);
        $response->assertJsonPath('0.items.0.source_post_url', 'https://t.me/parent/999');
    }

    public function test_cannot_access_other_users_draft(): void
    {
        $otherUser = User::factory()->create();
        $otherDraft = SourceDraft::factory()->forUser($otherUser)->create();

        $response = $this->actingAs($this->user)
            ->getJson("/api/source-drafts/{$otherDraft->id}/links");

        $response->assertStatus(403);
    }
}
