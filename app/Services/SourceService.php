<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\DiscoveryMethod;
use App\Enums\ExtractionStatus;
use App\Enums\ReviewStatus;
use App\Enums\SourceDraftStatus;
use App\Enums\SourceType;
use App\Jobs\ProcessSourceJob;
use App\Models\ContentSource;
use App\Models\SourceDraft;
use Illuminate\Support\Facades\DB;

class SourceService
{
    public function __construct(
        private readonly ExtractorFactory $extractorFactory,
    ) {}

    /**
     * Confirm and process a source draft.
     *
     * @param  array|null  $scrapeConfig  Optional scrape configuration (for TG filters)
     */
    public function confirmAndProcess(SourceDraft $draft, ?array $scrapeConfig = null): ContentSource
    {
        if ($scrapeConfig !== null) {
            $draft->update(['scrape_config' => $scrapeConfig]);
        }

        $source = DB::transaction(function () use ($draft) {
            $source = ContentSource::firstOrCreate(
                [
                    'user_id' => $draft->user_id,
                    'url' => $draft->raw_input,
                ],
                [
                    'type' => $draft->type,
                    'title' => $draft->channel_meta['title'] ?? null,
                    'extraction_status' => ExtractionStatus::Pending,
                    'discovery_method' => DiscoveryMethod::Manual,
                    'metadata' => [
                        'scrape_config' => $draft->scrape_config,
                    ],
                ]
            );

            $draft->update([
                'content_source_id' => $source->id,
                'status' => SourceDraftStatus::Processing,
            ]);

            return $source;
        });

        // if (in_array($draft->type, [SourceType::TelegramChannel, SourceType::TelegramPost])) {
        //     return $source;
        // }

        ProcessSourceJob::dispatch($source->id);

        return $source;
    }

    /**
     * Start indexing approved links.
     *
     * @param  array  $approvedUrls  List of approved/rejected URLs
     */
    public function startIndexing(SourceDraft $draft, array $approvedUrls = []): void
    {
        DB::transaction(function () use ($draft, $approvedUrls) {
            $draft->update(['status' => SourceDraftStatus::Indexing]);

            if (! empty($approvedUrls)) {
                $draft->contentSource
                    ?->originalItems()
                    ->whereIn('source_url', $approvedUrls)
                    ->update(['review_status' => ReviewStatus::Approved]);
            }

            $draft->contentSource->notebooks()->syncWithoutDetaching([$draft->knowledgeBase->id]);

            ProcessSourceJob::dispatch($draft->content_source_id);
        });
    }
}
