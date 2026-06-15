<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Models\ContentSource;

interface SourceExtractorInterface
{
    /**
     * Extract content from the source.
     */
    public function extract(ContentSource $source): void;
}
