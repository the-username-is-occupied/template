<?php

declare(strict_types=1);

namespace App\Enums;

enum MdBundleType: string
{
    case ActiveDelta = 'active_delta';
    case ActiveQuarter = 'active_quarter';
    case FrozenQuarter = 'frozen_quarter';
    case FrozenHalf = 'frozen_half';
    case FrozenFull = 'frozen_full';
}
