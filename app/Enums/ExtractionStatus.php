<?php

declare(strict_types=1);

namespace App\Enums;

enum ExtractionStatus: string
{
    case Pending = 'pending';
    case Uploading = 'uploading';
    case Extracting = 'extracting';
    case Extracted = 'extracted';
    case Error = 'error';
}
