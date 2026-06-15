<?php

declare(strict_types=1);

namespace App\Enums;

enum MdBundleStatus: string
{
    case Pending = 'pending';
    case Uploading = 'uploading';
    case Uploaded = 'uploaded';
    case Error = 'error';
}
