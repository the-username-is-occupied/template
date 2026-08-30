<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\ExtractionStatus;
use App\Events\ExtractionCompleted;
use App\Exceptions\UnsupportedSourceTypeException;
use App\Models\ContentSource;
use Illuminate\Support\Facades\Log;
use Throwable;

class SourceIndexingService
{
    public function __construct(
        private readonly ExtractorFactory $extractorFactory,
    ) {}

    public function process(string $contentSourceId): void
    {
        $source = ContentSource::findOrFail($contentSourceId);

        try {
            $extractor = $this->extractorFactory->make($source);
            $extractor->extract($source);
        } catch (UnsupportedSourceTypeException $e) {
            $source->update([
                'extraction_status' => ExtractionStatus::Error,
                'error_message' => $e->getMessage(),
                'error_code' => 'unsupported_source_type',
            ]);

            event(new ExtractionCompleted($source, false, $e->getMessage()));

        } catch (Throwable $e) {
            Log::channel('requests')->error('Transient extraction error:', [
                'content_source_id' => $contentSourceId,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
