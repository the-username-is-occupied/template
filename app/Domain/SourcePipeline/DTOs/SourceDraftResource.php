<?php

declare(strict_types=1);

namespace App\Domain\SourcePipeline\DTOs;

use App\Domain\Share\DTOs\BaseDTO;
use App\Enums\SourceDraftStatus;
use App\Enums\SourceType;
use App\Models\SourceDraft;

class SourceDraftResource extends BaseDTO
{
    public function __construct(
        public string $id,
        public string $user_id,
        public string $knowledge_base_id,
        public ?string $content_source_id,
        public ?SourceType $type,
        public string $raw_input,
        public ?array $channel_meta,
        public ?array $scrape_config,
        public bool $auto_update,
        public SourceDraftStatus $status,
        public string $created_at,
        public string $updated_at,
    ) {}

    public static function fromModel(SourceDraft $draft): self
    {
        return new self(
            id: (string) $draft->id,
            user_id: (string) $draft->user_id,
            knowledge_base_id: (string) $draft->knowledge_base_id,
            content_source_id: $draft->content_source_id ? (string) $draft->content_source_id : null,
            type: $draft->type,
            raw_input: $draft->raw_input,
            channel_meta: $draft->channel_meta,
            scrape_config: $draft->scrape_config,
            auto_update: $draft->auto_update,
            status: $draft->status,
            created_at: $draft->created_at->toISOString(),
            updated_at: $draft->updated_at->toISOString(),
        );
    }
}
