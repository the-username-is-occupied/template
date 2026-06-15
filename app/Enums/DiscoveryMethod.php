<?php

declare(strict_types=1);

namespace App\Enums;

enum DiscoveryMethod: string
{
    case Manual = 'manual';
    case AutoExtracted = 'auto_extracted';
}
