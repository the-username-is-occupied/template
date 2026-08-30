<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\OriginalItem;
use App\Services\BundleRenderer;
use Illuminate\Support\Collection;
use Tests\TestCase;

final class BundleRendererTest extends TestCase
{
    private BundleRenderer $renderer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->renderer = new BundleRenderer;
    }

    public function test_render_returns_md_with_base64url_header(): void
    {
        $item = new OriginalItem;
        $item->id = '550e8400-e29b-41d4-a716-446655440000';
        $item->title = 'Test Article';
        $item->full_text = 'This is the content of the article.';
        $item->published_at = now();

        $result = $this->renderer->render(new Collection([$item]));

        // Format: > {22-char base64url} {"title":"...","date":"..."}
        // 550e8400-e29b-41d4-a716-446655440000 in base64url = VQ6E4OKbQdSnFkRmVUQAAA
        $expectedId = $this->renderer->encodeItemId($item->id);
        $this->assertSame(22, strlen($expectedId));

        $lines = explode("\n", $result);
        $this->assertStringStartsWith('> ', $lines[0]);
        $this->assertStringContainsString($expectedId, $lines[0]);
        $this->assertStringContainsString('"title":"Test Article"', $lines[0]);
        $this->assertStringContainsString('"date"', $lines[0]);

        // Second line should be the full_text
        $this->assertStringContainsString('This is the content of the article.', $result);
    }

    public function test_render_uses_blank_line_separator_between_items(): void
    {
        $item1 = new OriginalItem;
        $item1->id = '550e8400-e29b-41d4-a716-446655440001';
        $item1->title = 'First';
        $item1->full_text = 'Content of first item.';
        $item1->published_at = now()->subDay();

        $item2 = new OriginalItem;
        $item2->id = '550e8400-e29b-41d4-a716-446655440002';
        $item2->title = 'Second';
        $item2->full_text = 'Content of second item.';
        $item2->published_at = now();

        $result = $this->renderer->render(new Collection([$item1, $item2]));

        // Should have two items separated by a blank line
        $this->assertStringContainsString("Content of first item.\n\n>", $result);

        // Verify we have two header lines
        $firstHeader = '> '.$this->renderer->encodeItemId($item1->id);
        $secondHeader = '> '.$this->renderer->encodeItemId($item2->id);
        $this->assertStringContainsString($firstHeader, $result);
        $this->assertStringContainsString($secondHeader, $result);
    }

    public function test_render_handles_empty_collection(): void
    {
        $result = $this->renderer->render(new Collection);

        $this->assertSame('', $result);
    }

    public function test_render_handles_null_title_or_date(): void
    {
        $item = new OriginalItem;
        $item->id = '550e8400-e29b-41d4-a716-446655440003';
        $item->title = null;
        $item->full_text = 'Some content without a title.';
        $item->published_at = null;

        $result = $this->renderer->render(new Collection([$item]));

        $this->assertStringContainsString('"title":""', $result);
        $this->assertStringContainsString('"date":""', $result);
        $this->assertStringContainsString('Some content without a title.', $result);
    }

    public function test_encode_item_id_gives_valid_base64url(): void
    {
        $uuid = '550e8400-e29b-41d4-a716-446655440000';
        $encoded = $this->renderer->encodeItemId($uuid);

        // Should be 22 characters
        $this->assertSame(22, strlen($encoded));

        // Should be URL-safe (no +, /, or =)
        $this->assertDoesNotMatchRegularExpression('/[+\/=]/', $encoded);

        // Should only contain alphanumeric, -, _ characters
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]+$/', $encoded);

        // Deterministic: same UUID → same result
        $this->assertSame($encoded, $this->renderer->encodeItemId($uuid));
    }

    public function test_render_with_multiple_items_all_have_valid_headers(): void
    {
        $items = collect(range(1, 5))->map(function (int $i): OriginalItem {
            $item = new OriginalItem;
            $item->id = sprintf('550e8400-e29b-41d4-a716-44665544000%d', $i);
            $item->title = "Article {$i}";
            $item->full_text = "Content of article {$i}.";
            $item->published_at = now()->subDays(5 - $i);

            return $item;
        });

        $result = $this->renderer->render($items);

        foreach ($items as $item) {
            $encodedId = $this->renderer->encodeItemId($item->id);
            $this->assertStringContainsString("> {$encodedId}", $result);
            $this->assertStringContainsString($item->full_text, $result);
        }
    }
}
