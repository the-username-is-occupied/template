<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\SourceExtractorInterface;
use App\Enums\SourceType;
use App\Exceptions\UnsupportedSourceTypeException;
use App\Models\ContentSource;
use App\Services\Extractors\TextExtractor;

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
            default => throw new UnsupportedSourceTypeException($type->value)
        };

        return app()->make($extractor);
    }
}
