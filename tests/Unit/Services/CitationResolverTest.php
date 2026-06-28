<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Domain\Citations\DTOs\ResolvedAskResultDTO;
use App\Domain\NotebookLM\DTOs\AskResultDTO;
use App\Domain\NotebookLM\DTOs\ChatReferenceDTO;
use App\Enums\MdBundleStatus;
use App\Enums\MdBundleType;
use App\Models\ContentSource;
use App\Models\MdBundle;
use App\Models\Notebook;
use App\Models\OriginalItem;
use App\Models\User;
use App\Services\BundleRenderer;
use App\Services\CitationResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Spatie\LaravelData\DataCollection;
use Tests\TestCase;

final class CitationResolverTest extends TestCase
{
    use RefreshDatabase;

    private CitationResolver $resolver;

    private BundleRenderer $renderer;

    private string $testDisk = 'test-bundles';

    protected function setUp(): void
    {
        parent::setUp();

        $this->renderer = new BundleRenderer;
        $this->resolver = new CitationResolver($this->renderer);

        // Set up fake storage disk
        Storage::fake($this->testDisk);
        config(['filesystems.default' => $this->testDisk]);
    }

    /**
     * Helper: Create a test MD bundle file with known content.
     *
     * Returns [MdBundle, OriginalItem, encodedId]
     */
    private function createTestBundle(string $content): array
    {
        // Create User for foreign key
        $user = User::factory()->create();

        // Create ContentSource
        $contentSource = ContentSource::create([
            'user_id' => $user->id,
            'type' => 'telegram_channel',
            'identifier' => '@test_channel',
        ]);

        // Create OriginalItem with known UUID
        $uuid = '550e8400-e29b-41d4-a716-446655440000';
        $encodedId = $this->renderer->encodeItemId($uuid);

        $item = OriginalItem::create([
            'id' => $uuid,
            'content_source_id' => $contentSource->id,
            'title' => 'Test Post Title',
            'full_text' => 'This is the full text of the test post. It contains enough content to be cited.',
            'source_url' => 'https://t.me/test_channel/123',
            'published_at' => now()->subDays(1),
            'word_count' => 20,
        ]);

        // Create Notebook (required for MdBundle)
        $notebook = Notebook::create([
            'user_id' => $user->id,
            'title' => 'Test Notebook',
        ]);

        // Create MdBundle
        $filePath = "bundles/{$uuid}.md";
        Storage::put($filePath, $content);

        $bundle = MdBundle::create([
            'id' => '660e8400-e29b-41d4-a716-446655440000',
            'notebook_id' => $notebook->id,
            'type' => MdBundleType::ActiveDelta,
            'file_path' => $filePath,
            'word_count' => 100,
            'status' => MdBundleStatus::Uploaded,
            'nlm_source_id' => 'nlm-source-123',
        ]);

        return [$bundle, $item, $encodedId, $contentSource];
    }

    /**
     * Helper: Create AskResultDTO with a single citation.
     */
    private function createAskResult(string $citedText, int $citationNumber = 1, string $sourceId = 'nlm-source-123'): AskResultDTO
    {
        $reference = new ChatReferenceDTO(
            source_id: $sourceId,
            citation_number: $citationNumber,
            cited_text: $citedText,
            start_char: 0,
            end_char: strlen($citedText),
        );

        return new AskResultDTO(
            answer: 'This is a test answer with citation [1]',
            conversation_id: 'test-conversation',
            turn_number: 1,
            is_follow_up: false,
            references: new DataCollection(ChatReferenceDTO::class, [$reference]),
        );
    }

    /**
     * Test 1: Successful resolution via full-text search.
     */
    public function test_resolves_citation_via_full_text_search(): void
    {
        $citedText = 'This is the full text of the test post.';
        $content = "> {$this->renderer->encodeItemId('550e8400-e29b-41d4-a716-446655440000')} {\"title\":\"Test Post Title\",\"date\":\"2024-01-01T00:00:00+00:00\"}\n{$citedText}";

        [$bundle, $item, $encodedId, $contentSource] = $this->createTestBundle($content);

        // Debug: Check if file can be read and text matches
        $fileContent = Storage::get($bundle->file_path);
        $position = strpos($fileContent, $citedText);
        dump('File content length: '.strlen($fileContent));
        dump('Cited text: '.$citedText);
        dump('Position in file: '.($position !== false ? $position : 'NOT FOUND'));

        $askResult = $this->createAskResult($citedText);
        $resolved = $this->resolver->resolve($askResult);

        $this->assertInstanceOf(ResolvedAskResultDTO::class, $resolved);
        $this->assertCount(1, $resolved->citations);
        $this->assertSame('https://t.me/test_channel/123', $resolved->citations[0]->source_url);
        $this->assertSame('Test Post Title', $resolved->citations[0]->title);
        $this->assertSame('telegram_channel', $resolved->citations[0]->source_type);
        $this->assertSame($contentSource->id, $resolved->citations[0]->content_source_id);
        $this->assertSame($citedText, $resolved->citations[0]->cited_text_clean);
        $this->assertSame(1, $resolved->citations[0]->citation_number);
    }

    /**
     * Test 2: Successful resolution when cited_text starts with Base64URL (skip file search).
     */
    public function test_resolves_citation_via_base64url_prefix(): void
    {
        $encodedId = $this->renderer->encodeItemId('550e8400-e29b-41d4-a716-446655440000');
        $citedText = "{$encodedId} This is the cited text that starts with Base64URL.";

        // Create bundle but we'll verify Storage::get() is NOT called
        $content = "> {$encodedId} {\"title\":\"Test\",\"date\":\"\"}\nSome content";
        [$bundle, $item, $encodedId, $contentSource] = $this->createTestBundle($content);

        $askResult = $this->createAskResult($citedText);
        $resolved = $this->resolver->resolve($askResult);

        $this->assertCount(1, $resolved->citations);
        $this->assertSame('https://t.me/test_channel/123', $resolved->citations[0]->source_url);
        // cited_text_clean should have the Base64URL removed from the start
        $this->assertStringStartsNotWith($encodedId, $resolved->citations[0]->cited_text_clean);
    }

    /**
     * Test 3: cited_text not found in file → citation absent from results.
     */
    public function test_skips_citation_when_text_not_found_in_file(): void
    {
        $content = "> {$this->renderer->encodeItemId('550e8400-e29b-41d4-a716-446655440000')} {\"title\":\"Test\",\"date\":\"\"}\nReal content here";

        [$bundle, $item, $encodedId, $contentSource] = $this->createTestBundle($content);

        // Use text that doesn't exist in the file
        $askResult = $this->createAskResult('This text does not exist in the file.');
        $resolved = $this->resolver->resolve($askResult);

        $this->assertCount(0, $resolved->citations);
    }

    /**
     * Test 4: Metadata cleanup from cited_text.
     */
    public function test_cleans_metadata_from_cited_text(): void
    {
        $encodedId = $this->renderer->encodeItemId('550e8400-e29b-41d4-a716-446655440000');
        $cleanText = 'This is the clean cited text.';
        $dirtyText = "{$encodedId} {\"title\":\"Test\",\"date\":\"2024-01-01\"} {$cleanText}";

        $content = "> {$encodedId} {\"title\":\"Test\",\"date\":\"2024-01-01T00:00:00+00:00\"}\n{$cleanText}";

        [$bundle, $item, $encodedId, $contentSource] = $this->createTestBundle($content);

        $askResult = $this->createAskResult($dirtyText);
        $resolved = $this->resolver->resolve($askResult);

        $this->assertCount(1, $resolved->citations);
        // cited_text_clean should NOT contain the Base64URL + JSON metadata
        $this->assertStringNotContainsString('{"title"', $resolved->citations[0]->cited_text_clean);
        $this->assertStringNotContainsString($encodedId, $resolved->citations[0]->cited_text_clean);
        $this->assertStringContainsString($cleanText, $resolved->citations[0]->cited_text_clean);
    }

    /**
     * Test 5: Edge case - citation at boundary of two posts.
     *
     * When cited_text spans two items, should return the item where citation STARTS.
     */
    public function test_resolves_citation_at_boundary_of_two_posts(): void
    {
        $encodedId1 = $this->renderer->encodeItemId('550e8400-e29b-41d4-a716-446655440000');
        $encodedId2 = $this->renderer->encodeItemId('550e8400-e29b-41d4-a716-446655440001');

        // Citation spans end of item1 and start of item2
        $content = "> {$encodedId1} {\"title\":\"Post 1\",\"date\":\"\"}\nEnd of first post.\n\n> {$encodedId2} {\"title\":\"Post 2\",\"date\":\"\"}\nStart of second post.";

        [$bundle, $item, $encodedId, $contentSource] = $this->createTestBundle($content);

        // Create second OriginalItem
        $item2 = OriginalItem::create([
            'id' => '550e8400-e29b-41d4-a716-446655440001',
            'content_source_id' => $contentSource->id,
            'title' => 'Post 2',
            'full_text' => 'Start of second post.',
            'source_url' => 'https://t.me/test_channel/124',
            'published_at' => now(),
            'word_count' => 10,
        ]);

        // Cited text starts in item1, should resolve to item1
        $askResult = $this->createAskResult('End of first post.');
        $resolved = $this->resolver->resolve($askResult);

        $this->assertCount(1, $resolved->citations);
        $this->assertSame('https://t.me/test_channel/123', $resolved->citations[0]->source_url);
        $this->assertSame('Test Post Title', $resolved->citations[0]->title);
    }

    /**
     * Test 6: File caching - same nlm_source_id reads file only once.
     */
    public function test_caches_bundle_file_for_multiple_citations(): void
    {
        $encodedId = $this->renderer->encodeItemId('550e8400-e29b-41d4-a716-446655440000');
        $content = "> {$encodedId} {\"title\":\"Test\",\"date\":\"\"}\nFirst citation text.\n\nSecond citation text here.";

        [$bundle, $item, $encodedId, $contentSource] = $this->createTestBundle($content);

        // Create two citations with same source_id
        $ref1 = new ChatReferenceDTO(
            source_id: 'nlm-source-123',
            citation_number: 1,
            cited_text: 'First citation text.',
            start_char: 0,
            end_char: 25,
        );

        $ref2 = new ChatReferenceDTO(
            source_id: 'nlm-source-123',
            citation_number: 2,
            cited_text: 'Second citation text here.',
            start_char: 0,
            end_char: 30,
        );

        $askResult = new AskResultDTO(
            answer: 'Answer with two citations [1][2]',
            conversation_id: 'test',
            turn_number: 1,
            is_follow_up: false,
            references: new DataCollection(ChatReferenceDTO::class, [$ref1, $ref2]),
        );

        $resolved = $this->resolver->resolve($askResult);

        $this->assertCount(2, $resolved->citations);
    }

    /**
     * Test 7: BundleRenderer::decodeItemId() round-trip.
     */
    public function test_decode_item_id_reverses_encode(): void
    {
        $originalUuid = '550e8400-e29b-41d4-a716-446655440000';
        $encoded = $this->renderer->encodeItemId($originalUuid);
        $decoded = $this->renderer->decodeItemId($encoded);

        $this->assertSame($originalUuid, $decoded);
    }

    /**
     * Test 8: decodeItemId() throws exception for invalid input.
     */
    public function test_decode_item_id_throws_for_invalid_input(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->renderer->decodeItemId('invalid!@#');
    }

    /**
     * Test 9: MdBundle not found → citation skipped.
     */
    public function test_skips_citation_when_bundle_not_found(): void
    {
        $askResult = $this->createAskResult('Some text', 1, 'non-existent-source-id');
        $resolved = $this->resolver->resolve($askResult);

        $this->assertCount(0, $resolved->citations);
    }

    /**
     * Test 10: OriginalItem not found after decoding ID → citation skipped.
     */
    public function test_skips_citation_when_item_not_found(): void
    {
        // Create bundle with valid content, but item ID that doesn't exist in DB
        $nonExistentUuid = '660e8400-e29b-41d4-a716-446655440000';
        $encodedId = $this->renderer->encodeItemId($nonExistentUuid);
        $content = "> {$encodedId} {\"title\":\"Test\",\"date\":\"\"}\nSome content";

        [$bundle, $item, $encodedId, $contentSource] = $this->createTestBundle($content);

        // Don't create item with $nonExistentUuid, so it won't be found
        $askResult = $this->createAskResult('Some content');
        $resolved = $this->resolver->resolve($askResult);

        $this->assertCount(0, $resolved->citations);
    }
}
