<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\SourceExtractorInterface;
use App\Enums\SourceType;
use App\Exceptions\UnsupportedSourceTypeException;
use App\Models\ContentSource;
use App\Services\Extractors\NlmFileExtractor;
use App\Services\Extractors\TelegramExtractor;
use App\Services\Extractors\TextExtractor;
use App\Services\Extractors\WebsiteExtractor;
use App\Services\Extractors\YouTubeExtractor;

class ExtractorFactory
{
    /**
     * Resolve the appropriate extractor for the given source.
     *
     * @throws UnsupportedSourceTypeException
     */
    public function make(ContentSource $source): SourceExtractorInterface
    {
        $type = $source->type;

        if (! $type instanceof SourceType) {
            $type = SourceType::from($type);
        }

        $extractor = match ($type) {
            SourceType::Text => TextExtractor::class,
            SourceType::TelegramChannel => TelegramExtractor::class,
            SourceType::YoutubeChannel, SourceType::YoutubeVideo => YouTubeExtractor::class,
            SourceType::Website => WebsiteExtractor::class,
            SourceType::Pdf, SourceType::Docx, SourceType::Csv, SourceType::Pptx,
            SourceType::Epub, SourceType::Mp3, SourceType::Mp4, SourceType::Jpg,
            SourceType::Png, SourceType::Audio, SourceType::Video, SourceType::Image => NlmFileExtractor::class,
            default => throw new UnsupportedSourceTypeException($type->value)
        };

        return app()->make($extractor);
    }
}
