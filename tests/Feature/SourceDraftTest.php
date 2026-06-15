<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Telegram\DTOs\ChannelInfoResponse;
use App\Domain\Telegram\TGScraperService;
use App\Domain\YouTube\DTOs\ChannelInfoData;
use App\Enums\SourceDraftStatus;
use App\Enums\SourceType;
use App\Events\SourceDraftError;
use App\Events\SourceMetaLoaded;
use App\Models\Notebook;
use App\Models\SourceDraft;
use App\Models\User;
use App\Services\SmartUrlDetector;
use App\Services\SourceDraftService;
use App\Services\YouTubeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

final class SourceDraftTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Notebook $notebook;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->notebook = Notebook::factory()->create(['user_id' => $this->user->id]);
    }

    public function test_smart_url_detector_detects_telegram_channel(): void
    {
        $detector = new SmartUrlDetector;

        $result = $detector->detect('https://t.me/habr_com');
        $this->assertEquals(SourceType::TelegramChannel, $result->type);
        $this->assertEquals('habr_com', $result->normalizedId);

        $result = $detector->detect('https://t.me/s/durov');
        $this->assertEquals(SourceType::TelegramChannel, $result->type);
        $this->assertEquals('durov', $result->normalizedId);
    }

    public function test_smart_url_detector_detects_youtube_channel(): void
    {
        $detector = new SmartUrlDetector;

        $result = $detector->detect('https://youtube.com/@GoogleDevelopers');
        $this->assertEquals(SourceType::YoutubeChannel, $result->type);
        $this->assertEquals('GoogleDevelopers', $result->normalizedId);

        $result = $detector->detect('https://youtube.com/channel/UC_x5XG1OV2P6uZZ5FSM9Ttw');
        $this->assertEquals(SourceType::YoutubeChannel, $result->type);
        $this->assertEquals('UC_x5XG1OV2P6uZZ5FSM9Ttw', $result->normalizedId);

        $result = $detector->detect('https://youtube.com/c/GoogleDevelopers');
        $this->assertEquals(SourceType::YoutubeChannel, $result->type);
        $this->assertEquals('GoogleDevelopers', $result->normalizedId);
    }

    public function test_smart_url_detector_detects_youtube_video(): void
    {
        $detector = new SmartUrlDetector;

        $result = $detector->detect('https://youtube.com/watch?v=dQw4w9WgXcQ');
        $this->assertEquals(SourceType::YoutubeVideo, $result->type);
        $this->assertEquals('dQw4w9WgXcQ', $result->normalizedId);

        $result = $detector->detect('https://youtu.be/dQw4w9WgXcQ');
        $this->assertEquals(SourceType::YoutubeVideo, $result->type);
        $this->assertEquals('dQw4w9WgXcQ', $result->normalizedId);
    }

    public function test_smart_url_detector_detects_youtube_playlist_as_channel(): void
    {
        $detector = new SmartUrlDetector;

        $result = $detector->detect('https://youtube.com/playlist?list=PLrAXtmRdnEQeiGU6GBsMcu4F8xLZXUx9S');
        $this->assertEquals(SourceType::YoutubeChannel, $result->type);
        $this->assertEquals('PLrAXtmRdnEQeiGU6GBsMcu4F8xLZXUx9S', $result->normalizedId);
    }

    public function test_smart_url_detector_detects_pdf(): void
    {
        $detector = new SmartUrlDetector;

        $result = $detector->detect('https://example.com/document.pdf');
        $this->assertEquals(SourceType::Pdf, $result->type);
        $this->assertStringEndsWith('.pdf', $result->normalizedId);
    }

    public function test_smart_url_detector_detects_website(): void
    {
        $detector = new SmartUrlDetector;

        $result = $detector->detect('https://example.com/article');
        $this->assertEquals(SourceType::Website, $result->type);
        $this->assertEquals('https://example.com/article', $result->normalizedId);
    }

    public function test_smart_url_detector_parses_multiple_urls(): void
    {
        $detector = new SmartUrlDetector;

        $urls = $detector->parseRawInput("https://t.me/channel1\nhttps://youtube.com/@test https://example.com");
        $this->assertCount(3, $urls);
        $this->assertEquals('https://t.me/channel1', $urls[0]);
        $this->assertEquals('https://youtube.com/@test', $urls[1]);
        $this->assertEquals('https://example.com', $urls[2]);
    }

    public function test_create_source_draft_with_tg_url(): void
    {
        $this->mock(TGScraperService::class, function ($mock) {
            $mock->shouldReceive('getChannelInfo')
                ->andReturn(new ChannelInfoResponse(
                    channel: 'habr_com',
                    title: 'Habr',
                    description: 'Tech articles',
                    members: '250K',
                    avatar_url: 'https://example.com/avatar.jpg'
                ));
        });

        Event::fake([
            SourceMetaLoaded::class,
        ]);

        $response = $this->actingAs($this->user)
            ->postJson('/api/source-drafts', [
                'knowledge_base_id' => $this->notebook->id,
                'raw_input' => 'https://t.me/habr_com',
            ]);

        $response->assertStatus(201);
        $response->assertJsonStructure([
            '*' => ['id', 'type', 'raw_input', 'status'],
        ]);

        $this->assertDatabaseHas('source_drafts', [
            'user_id' => $this->user->id,
            'knowledge_base_id' => $this->notebook->id,
            'raw_input' => 'https://t.me/habr_com',
            'type' => SourceType::TelegramChannel->value,
        ]);
    }

    public function test_create_multiple_source_drafts(): void
    {
        $this->mock(TGScraperService::class, function ($mock) {
            $mock->shouldReceive('getChannelInfo')
                ->andReturn(new ChannelInfoResponse(
                    channel: 'test',
                    title: 'Test',
                    description: null,
                    members: null,
                    avatar_url: null
                ));
        });

        $this->mock(YouTubeService::class, function ($mock) {
            $mock->shouldReceive('getChannelInfo')
                ->andReturn(new ChannelInfoData(
                    id: 'UCtest123',
                    title: 'Test Channel',
                    description: 'A test YouTube channel',
                    handle: '@test',
                    avatar_url: 'https://example.com/avatar.jpg',
                    published_at: null,
                    subscribers_count: 1000,
                    view_count: 10000,
                    video_count: 50,
                ));
        });

        Event::fake([
            SourceMetaLoaded::class,
        ]);

        $response = $this->actingAs($this->user)
            ->postJson('/api/source-drafts', [
                'knowledge_base_id' => $this->notebook->id,
                'raw_input' => "https://t.me/channel1\nhttps://youtube.com/@test",
            ]);

        $response->assertStatus(201);
        $response->assertJsonCount(2);

        $this->assertDatabaseCount('source_drafts', 2);
    }

    public function test_show_source_draft(): void
    {
        $draft = SourceDraft::factory()
            ->forUser($this->user)
            ->forNotebook($this->notebook)
            ->create();

        $response = $this->actingAs($this->user)
            ->getJson("/api/source-drafts/{$draft->id}");

        $response->assertStatus(200);
        $response->assertJson([
            'id' => $draft->id,
            'raw_input' => $draft->raw_input,
        ]);
    }

    public function test_cannot_access_other_users_draft(): void
    {
        $otherUser = User::factory()->create();
        $otherNotebook = Notebook::factory()->forUser($otherUser)->create();
        $draft = SourceDraft::factory()
            ->forUser($otherUser)
            ->forNotebook($otherNotebook)
            ->create();

        $response = $this->actingAs($this->user)
            ->getJson("/api/source-drafts/{$draft->id}");

        $response->assertStatus(403);
    }

    public function test_abandon_source_draft(): void
    {
        $draft = SourceDraft::factory()
            ->forUser($this->user)
            ->forNotebook($this->notebook)
            ->awaitingConfirm()
            ->create();

        $response = $this->actingAs($this->user)
            ->deleteJson("/api/source-drafts/{$draft->id}");

        $response->assertStatus(200);
        $response->assertJson(['message' => 'Draft abandoned.']);

        $this->assertDatabaseHas('source_drafts', [
            'id' => $draft->id,
            'status' => SourceDraftStatus::Abandoned->value,
        ]);
    }

    public function test_source_draft_service_handle_meta_loaded(): void
    {
        $draft = SourceDraft::factory()
            ->forUser($this->user)
            ->forNotebook($this->notebook)
            ->fetchingMeta()
            ->create();

        Event::fake([
            SourceMetaLoaded::class,
        ]);

        $service = new SourceDraftService(
            new SmartUrlDetector,
            $this->mock(TGScraperService::class),
            $this->mock(YouTubeService::class),
        );
        $service->handleMetaLoaded($draft, [
            'title' => 'Test Channel',
            'description' => 'A test channel',
            'members' => '1000',
            'avatar_url' => 'https://example.com/avatar.jpg',
        ]);

        $this->assertEquals(SourceDraftStatus::AwaitingConfirm, $draft->fresh()->status);
        $this->assertEquals('Test Channel', $draft->fresh()->channel_meta['title']);
    }

    public function test_source_draft_service_handle_meta_error(): void
    {
        $draft = SourceDraft::factory()
            ->forUser($this->user)
            ->forNotebook($this->notebook)
            ->fetchingMeta()
            ->create();

        Event::fake([
            SourceDraftError::class,
        ]);

        $service = new SourceDraftService(
            new SmartUrlDetector,
            $this->mock(TGScraperService::class),
            $this->mock(YouTubeService::class),
        );
        $service->handleMetaError($draft, 'test_error', 'Test error message');

        $this->assertEquals(SourceDraftStatus::Abandoned, $draft->fresh()->status);
    }

    public function test_unauthenticated_user_cannot_access_drafts(): void
    {
        $response = $this->postJson('/api/source-drafts', [
            'knowledge_base_id' => $this->notebook->id,
            'raw_input' => 'https://t.me/test',
        ]);

        $response->assertStatus(401);
    }
}
