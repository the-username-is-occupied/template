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
 * Resolves NLM citations to actual OriginalItem records for BundleRenderer2 format.
 *
 * New bundle format (BundleRenderer2):
 * # {title}
 * **Метаданные:**
 * * **published_date:** {date}
 * * **{key}:** {value}
 * * **internal_id:** {id}
 * {full_text}
 *
 * ---
 *
 * Algorithm (per citation):
 * Step 0 — Clean cited_text from embedded metadata
 * Step 1 — Detect strategy: starts with internal_id pattern → extract directly, else steps 2-3
 * Step 2 — Find MdBundle by nlm_source_id
 * Step 3 — Read MD file from disk (cached per nlm_source_id)
 * Step 4 — Find position of cited_text in file (exact strpos, then regex \s+ fallback)
 * Step 5 — Extract internal_id by scanning backward to metadata section
 * Step 6 — Find OriginalItem by ID and return CitationData
 */
class CitationResolver2
{
    /**
     * In-memory cache of bundle file content, keyed by nlm_source_id.
     * Avoids re-reading the same file for multiple citations in one resolve() call.
     *
     * @var array<string, string>
     */
    private array $bundleCache = [];

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
            suggested: $askResult->suggested,
        );
    }

    private function resolveSingle(ChatReferenceDTO $reference): ?CitationData
    {
        try {
            // Step 0: Clean metadata from cited_text
            $citedTextClean = $this->cleanMetadata($reference->cited_text);

            // Step1: Detect strategy - check if cited_text starts with UUID header pattern
            // New format: # {UUID} at the beginning
            // UUID pattern: [0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}
            if (preg_match('/^#\s*([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})/im', $reference->cited_text, $matches) === 1) {
                // Strategy A: cited_text starts with #UUID header → extract directly
                $itemId = $matches[1];
            } else {
                // Strategy B: Full-text search (steps 2-4)
                $itemId = $this->resolveByFullText($reference, $citedTextClean);
                if ($itemId === null) {
                    Log::warning('CitationResolver2: Full-text search returned null');

                    return null;
                }
            }

            // Step 6: Find OriginalItem
            $item = OriginalItem::find($itemId);
            if ($item === null) {
                Log::warning('CitationResolver2: OriginalItem not found', ['item_id' => $itemId]);

                return null;
            }

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
            Log::warning('CitationResolver2: Failed to resolve citation', [
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
     * Returns item ID (UUID string), or null if cited_text not found.
     *
     * @throws \RuntimeException If MdBundle not found or file unreadable
     */
    private function resolveByFullText(ChatReferenceDTO $reference, string $citedTextClean): ?string
    {
        // Step 2: Find MdBundle by nlm_source_id
        $bundle = MdBundle::where('nlm_source_id', $reference->source_id)->first();
        if ($bundle === null) {
            Log::warning('CitationResolver2: MdBundle not found', ['nlm_source_id' => $reference->source_id]);

            return null;
        }

        // Step 3: Read MD file (with caching)
        $fileContent = $this->getBundleContent($bundle);
        if ($fileContent === null) {
            return null;
        }

        // Step 4: Find position of cited_text_clean in file
        $position = $this->findCitationPosition($fileContent, $citedTextClean);
        if ($position === -1) {
            Log::warning('CitationResolver2: cited_text not found in bundle file', [
                'nlm_source_id' => $reference->source_id,
                'cited_text_snippet' => substr($citedTextClean, 0, 100)
            ]);

            return null;
        }

        // Step 5: Extract internal_id (scan backward to metadata section)
        $itemId = $this->extractItemId($fileContent, $position);
        if ($itemId === null) {
            Log::warning('CitationResolver2: Could not extract item ID from bundle file', [
                'nlm_source_id' => $reference->source_id,
                'position' => $position,
            ]);

            return null;
        }

        return $itemId;
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
            Log::warning('CitationResolver2: Bundle file not found', [
                'file_path' => $bundle->file_path,
                'disk' => config('filesystems.default'),
            ]);

            return null;
        }

        $content = Storage::disk('bundles')->get($bundle->file_path);
        if ($content === false || $content === null) {
            Log::warning('CitationResolver2: Failed to read bundle file', [
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
     * 1. Exact strpos (fast path).
     * 2. Regex fallback: whitespace in citedTextClean is replaced with \s+ so that
     *    newlines inside the MD bundle match the spaces NLM puts in cited_text.
     *    Returns the byte offset directly in $fileContent — no position mapping needed.
     * Returns -1 if not found.
     */
    private function findCitationPosition(string $fileContent, string $citedTextClean): int
    {
        // Fast path: last exact match
        $position = strrpos($fileContent, $citedTextClean);
        if ($position !== false) {
            return $position;
        }

        // Fallback: build a pattern where every whitespace sequence in the cited text
        // can match any whitespace (including \n) in the file.
        $escaped = preg_quote($citedTextClean, '/');
        $pattern = '/'.preg_replace('/\s+/', '\\s+', $escaped).'/u';

        if (preg_match_all($pattern, $fileContent, $matches, PREG_OFFSET_CAPTURE) >= 1) {
            return (int) end($matches[0])[1];
        }

        return -1;
    }

    /**
     * Extract item ID (UUID) from bundle file.
     *
     * Scans backward from $position to find the nearest header `#{UUID}`,
     * then extracts the UUID from that header.
     *
     * @return string|null UUID string or null if not found
     */
    private function extractItemId(string $fileContent, int $position): ?string
    {
        // Get the portion of file before the match position
        $before = substr($fileContent, 0, $position);

        // Strategy 1: Find the last occurrence of `# {UUID}` header before the position
        // New format: # {UUID} at the beginning of each item
        // UUID pattern: [0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}
        if (preg_match_all('/^#\s*([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})/im', $before, $matches, PREG_OFFSET_CAPTURE) > 0) {
            // Get the last match (closest to the citation position)
            $lastMatch = end($matches[1]);
            $itemId = $lastMatch[0];

            return $itemId;
        }

        Log::warning('CitationResolver2: extractItemId - UUID header not found', [
            'position' => $position,
            'last_2000_chars' => substr($before, -2000),
        ]);

        return null;
    }
}
