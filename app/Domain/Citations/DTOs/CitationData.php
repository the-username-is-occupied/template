<?php

declare(strict_types=1);

namespace App\Domain\Citations\DTOs;

use Spatie\LaravelData\Data;

/**
 * Edge cases:
 * - cited_text starts with Base64URL → file search is skipped, ID extracted directly from first 22 chars
 * - cited_text contains Base64URL inline → metadata is removed at step 0, search uses cleaned text
 * - cited_text crosses boundary of two posts → returns item where citation STARTS (nearest `>` header above match position)
 * - cited_text not found in file → citation is skipped (NLM may have slightly changed the wording)
 */
class CitationData extends Data
{
    public function __construct(
        public string $source_url,
        public ?string $title,
        public ?string $published_at,
        public string $source_type,
        public string $content_source_id,
        public string $cited_text_clean,
        public int $citation_number,
    ) {}

    public function typeLabel(): string
    {
        return match ($this->source_type) {
            'telegram_channel' => '[telegram]',
            'youtube_channel' => '[youtube]',
            default => 'ссылка',
        };
    }
}
