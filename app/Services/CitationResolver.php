<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\Citations\DTOs\CitationData;
use App\Domain\Citations\DTOs\ResolvedAskResultDTO;
use App\Domain\NotebookLM\DTOs\AskResultDTO;
use App\Domain\NotebookLM\DTOs\ChatReferenceDTO;
use App\Models\MdBundle;
use App\Models\OriginalItem;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Spatie\LaravelData\DataCollection;

/**
 * Resolves NLM citations to actual OriginalItem records.
 *
 * Algorithm (per citation):
 * Step 0 — Clean cited_text from embedded metadata (Base64URL + JSON)
 * Step 1 — Detect strategy: starts with Base64URL? → skip to step 5, else steps 2-4
 * Step 2 — Find MdBundle by nlm_source_id
 * Step 3 — Read MD file from disk (cached per nlm_source_id)
 * Step 4 — Find position of cited_text_clean in file
 * Step 5 — Extract Base64URL identifier (scan backward to `>` header)
 * Step 6 — Validate and decode Base64URL to UUID
 * Step 7 — Find OriginalItem and return CitationData
 *
 * Edge cases:
 * - cited_text starts with Base64URL → file search skipped, ID extracted directly
 * - cited_text contains Base64URL inline → metadata removed at step 0
 * - cited_text crosses boundary of two posts → returns item where citation STARTS
 * - cited_text not found in file → citation skipped (NLM may have rephrased)
 * - Multiple citations with same nlm_source_id → file read once (cached)
 */
class CitationResolver
{
    private BundleRenderer $bundleRenderer;

    /**
     * In-memory cache of bundle file content, keyed by nlm_source_id.
     * Avoids re-reading the same file for multiple citations in one resolve() call.
     *
     * @var array<string, string>
     */
    private array $bundleCache = [];

    public function __construct(BundleRenderer $bundleRenderer)
    {
        $this->bundleRenderer = $bundleRenderer;
    }

    public function resolve(AskResultDTO $askResult): ResolvedAskResultDTO
    {
        $citations = [];

        foreach ($askResult->references as $reference) {
            /** @var ChatReferenceDTO $reference */
            $citation = $this->resolveSingle($reference);
            if ($citation !== null) {
                $citations[] = $citation;
            }
        }

        return new ResolvedAskResultDTO(
            answer: $askResult->answer,
            citations: new DataCollection(CitationData::class, $citations),
        );
    }

    private function resolveSingle(ChatReferenceDTO $reference): ?CitationData
    {
        try {
            // Step 0: Clean metadata from cited_text
            $citedTextClean = $this->cleanMetadata($reference->cited_text);

            Log::debug('CitationResolver: Resolving single citation', [
                'citation_number' => $reference->citation_number,
                'cited_text_length' => strlen($reference->cited_text),
                'cited_text_clean_length' => strlen($citedTextClean),
                'source_id' => $reference->source_id,
            ]);

            // Step 1: Detect strategy
            if (preg_match('/^[A-Za-z0-9_-]{22}(\s|$)/', $reference->cited_text) === 1) {
                // Strategy A: cited_text starts with Base64URL → skip file search
                $encodedId = substr($reference->cited_text, 0, 22);
                // Decode immediately (step 6)
                $uuid = $this->bundleRenderer->decodeItemId($encodedId);
                Log::debug('CitationResolver: Using Base64URL strategy', ['encoded_id' => $encodedId, 'uuid' => $uuid]);
            } else {
                // Strategy B: Full-text search (steps 2-4)
                Log::debug('CitationResolver: Using full-text search strategy');
                $uuid = $this->resolveByFullText($reference, $citedTextClean);
                if ($uuid === null) {
                    Log::warning('CitationResolver: Full-text search returned null');

                    return null; // cited_text not found in file, skip
                }
                Log::debug('CitationResolver: Full-text search returned UUID', ['uuid' => $uuid]);
            }

            // Step 7: Find OriginalItem
            $item = OriginalItem::find($uuid);
            if ($item === null) {
                Log::warning('CitationResolver: OriginalItem not found', ['uuid' => $uuid]);

                return null;
            }

            Log::debug('CitationResolver: Found OriginalItem', ['item_id' => $item->id, 'source_url' => $item->source_url]);

            // Get source_type from ContentSource relationship
            $sourceType = 'unknown';
            if ($item->contentSource !== null) {
                $sourceType = $item->contentSource->type->value ?? 'unknown';
            }

            return new CitationData(
                source_url: $item->source_url ?? '',
                title: $item->title,
                published_at: $item->published_at?->toIso8601String(),
                source_type: $sourceType,
                content_source_id: $item->content_source_id,
                cited_text_clean: $citedTextClean,
                citation_number: $reference->citation_number,
            );

        } catch (\Throwable $e) {
            Log::warning('CitationResolver: Failed to resolve citation', [
                'citation_number' => $reference->citation_number,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return null;
        }
    }

    /**
     * Clean metadata pattern from cited_text.
     *
     * Pattern: Base64URL (22 chars) + space + JSON object {"..."}
     * Removes ALL occurrences (not just at start).
     */
    private function cleanMetadata(string $citedText): string
    {
        // Pattern: 22-char Base64URL + space + JSON object
        // Matches: VQ6E4OKbQdSnFkRmVUQAAA {"title":"...","date":"..."}
        $pattern = '/[A-Za-z0-9_-]{22} \{"[^"]*"[^}]*\}/';

        return preg_replace($pattern, '', $citedText) ?? $citedText;
    }

    /**
     * Resolve citation by full-text search in bundle file.
     *
     * Returns decoded UUID string, or null if cited_text not found.
     *
     * @throws \RuntimeException If MdBundle not found or file unreadable
     */
    private function resolveByFullText(ChatReferenceDTO $reference, string $citedTextClean): ?string
    {
        // Step 2: Find MdBundle by nlm_source_id
        $bundle = MdBundle::where('nlm_source_id', $reference->source_id)->first();
        if ($bundle === null) {
            Log::warning('CitationResolver: MdBundle not found', ['nlm_source_id' => $reference->source_id]);

            return null;
        }

        // Step 3: Read MD file (with caching)
        $fileContent = $this->getBundleContent($bundle);
        if ($fileContent === null) {
            return null;
        }

        // Debug: log file content length and cited text
        Log::debug('CitationResolver: Searching in bundle file', [
            'nlm_source_id' => $reference->source_id,
            'file_length' => strlen($fileContent),
            'cited_text_clean' => $citedTextClean,
        ]);

        // Step 4: Find position of cited_text_clean in file
        $position = $this->findCitationPosition($fileContent, $citedTextClean);
        if ($position === -1) {
            Log::warning('CitationResolver: cited_text not found in bundle file', [
                'nlm_source_id' => $reference->source_id,
                'cited_text_snippet' => substr($citedTextClean, 0, 100),
                'file_snippet' => substr($fileContent, 0, 500),
            ]);

            return null;
        }

        // Step 5: Extract Base64URL identifier (scan backward to `>` header)
        $encodedId = $this->extractItemId($fileContent, $position);
        if ($encodedId === null) {
            Log::warning('CitationResolver: Could not extract item ID from bundle file', [
                'nlm_source_id' => $reference->source_id,
                'position' => $position,
            ]);

            return null;
        }

        // Step 6: Validate and decode
        if (preg_match('/^[A-Za-z0-9_-]{22}$/', $encodedId) !== 1) {
            Log::warning('CitationResolver: Invalid Base64URL identifier', ['encoded_id' => $encodedId]);

            return null;
        }

        return $this->bundleRenderer->decodeItemId($encodedId);
    }

    /**
     * Get bundle file content, with in-memory caching.
     *
     * Returns file content string, or null on failure.
     */
    private function getBundleContent(MdBundle $bundle): ?string
    {
        $cacheKey = $bundle->nlm_source_id;

        if (isset($this->bundleCache[$cacheKey])) {
            return $this->bundleCache[$cacheKey];
        }

        if (! Storage::disk('bundles')->exists($bundle->file_path)) {
            Log::warning('CitationResolver: Bundle file not found', [
                'file_path' => $bundle->file_path,
                'disk' => config('filesystems.default'),
            ]);

            return null;
        }

        $content = Storage::disk('bundles')->get($bundle->file_path);
        if ($content === false || $content === null) {
            Log::warning('CitationResolver: Failed to read bundle file', [
                'file_path' => $bundle->file_path,
                'storage_return' => $content,
            ]);

            return null;
        }

        $this->bundleCache[$cacheKey] = $content;

        return $content;
    }

    /**
     * Find the position of cited_text in file content.
     *
     * Uses strpos() for speed; falls back to preg_match() for fuzzy matching if needed.
     * Returns -1 if not found.
     */
    private function findCitationPosition(string $fileContent, string $citedTextClean): int
    {
        // Try exact match first
        $position = strpos($fileContent, $citedTextClean);
        if ($position !== false) {
            return $position;
        }

        // Try with reduced whitespace (NLM may alter formatting)
        $normalizedFile = preg_replace('/\s+/', ' ', $fileContent) ?? $fileContent;
        $normalizedCite = preg_replace('/\s+/', ' ', $citedTextClean) ?? $citedTextClean;

        $position = strpos($normalizedFile, $normalizedCite);
        if ($position !== false) {
            // Map normalized position back to original position (approximate)
            return $this->approximatePosition($fileContent, $normalizedFile, $position);
        }

        return -1;
    }

    /**
     * Approximate original position from normalized position.
     *
     * Walks both strings up to $normalizedPos, counting chars in original.
     * Returns approximate position in original string.
     */
    private function approximatePosition(string $original, string $normalized, int $normalizedPos): int
    {
        $origPos = 0;
        $normPos = 0;

        while ($normPos < $normalizedPos && $origPos < strlen($original)) {
            if ($normalized[$normPos] === $original[$origPos]) {
                $normPos++;
                $origPos++;
            } else {
                // Skip extra whitespace in original
                if (ctype_space($original[$origPos]) && ! ctype_space($normalized[$normPos])) {
                    $origPos++;
                } else {
                    // Mismatch we can't resolve, return best guess
                    break;
                }
            }
        }

        return $origPos;
    }

    /**
     * Extract Base64URL item ID from bundle file.
     *
     * Scans backward from $position to find the nearest `>` header line,
     * then extracts the 22-char Base64URL after the `> ` prefix.
     */
    private function extractItemId(string $fileContent, int $position): ?string
    {
        // Get the portion of file before the match position
        $before = substr($fileContent, 0, $position);

        // Find the last occurrence of a line starting with `> ` followed by Base64URL
        // The header format is: > {22-char-base64url} {"title":"...","date":"..."}
       // Стало (preg_match_all — берём последнее совпадение)
if (preg_match_all('/^> ([A-Za-z0-9_-]{22}) /m', $before, $matches) > 0) {
    return end($matches[1]);
}

        return null;
    }
}
