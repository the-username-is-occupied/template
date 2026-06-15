<?php

declare(strict_types=1);

namespace App\Services\Extractors;

use App\Contracts\SourceExtractorInterface;
use App\Enums\ExtractionStatus;
use App\Events\ExtractionCompleted;
use App\Models\ContentSource;
use Illuminate\Support\Facades\Storage;

class TextExtractor implements SourceExtractorInterface
{
    public function extract(ContentSource $source): void
    {
        $fileRef = $source->file_ref;

        if (! $fileRef) {
            $source->update([
                'extraction_status' => ExtractionStatus::Error,
                'error_message' => 'No file reference provided.',
                'error_code' => 'missing_file_ref',
            ]);

            return;
        }

        $disk = Storage::disk(config('filesystems.default'));

        if (! $disk->exists($fileRef)) {
            $source->update([
                'extraction_status' => ExtractionStatus::Error,
                'error_message' => 'File not found: '.$fileRef,
                'error_code' => 'file_not_found',
            ]);

            return;
        }

        $content = $disk->get($fileRef);
        $wordCount = str_word_count(strip_tags($content));

        $source->originalItems()->create([
            'title' => basename($fileRef),
            'full_text' => $content,
            'source_url' => $fileRef,
            'published_at' => now(),
            'word_count' => $wordCount,
            'metadata' => [],
        ]);

        $source->update([
            'extraction_status' => ExtractionStatus::Extracted,
            'error_message' => null,
            'error_code' => null,
        ]);

        event(new ExtractionCompleted($source));
    }
}
