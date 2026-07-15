<?php

declare(strict_types=1);

namespace App\Enums;

enum PreprocessStatus: string
{
    case INSUFFICIENT = 'insufficient';
    case SUFFICIENT = 'sufficient';
}
