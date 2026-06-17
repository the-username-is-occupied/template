<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ContentSource;
use App\Models\SourceDraft;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class TelegramWebhookControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_webhook_upload_returns_200(): void
    {
        $source = ContentSource::factory()->create();
        SourceDraft::factory()->withContentSource($source)->create();

        $posts = [
            [
                'id' => 123,
                'url' => 'https://t.me/test/123',
                'text' => 'Test post',
                'date' => '2024-01-01T00:00:00Z',
            ],
        ];

        $response = $this->postJson('/api/webhooks/telegram-scraper', [
            'action' => 'upload',
            'content_source_id' => $source->id,
            'posts' => $posts,
        ]);

        $response->assertStatus(200);
    }

    public function test_webhook_done_returns_200(): void
    {
        $source = ContentSource::factory()->create();

        $response = $this->postJson('/api/webhooks/telegram-scraper', [
            'action' => 'done',
            'content_source_id' => $source->id,
        ]);

        $response->assertStatus(200);
    }

    public function test_webhook_validates_action(): void
    {
        $response = $this->postJson('/api/webhooks/telegram-scraper', [
            'action' => 'invalid',
            'content_source_id' => 'invalid-uuid',
        ]);

        $response->assertStatus(422);
    }

    public function test_webhook_requires_content_source_id(): void
    {
        $response = $this->postJson('/api/webhooks/telegram-scraper', [
            'action' => 'upload',
        ]);

        $response->assertStatus(422);
    }

    public function test_webhook_upload_with_empty_posts_returns_200(): void
    {
        $source = ContentSource::factory()->create();

        $response = $this->postJson('/api/webhooks/telegram-scraper', [
            'action' => 'upload',
            'content_source_id' => $source->id,
            'posts' => [],
        ]);

        $response->assertStatus(200);
    }
}
