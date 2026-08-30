<?php

declare(strict_types=1);

namespace App\Enums;

use InvalidArgumentException;

enum MdBundleType: string
{
    case ActiveDelta = 'active_delta';
    case ActiveQuarter = 'active_quarter';
    case FrozenQuarter = 'frozen_quarter';
    case FrozenHalf = 'frozen_half';
    case FrozenFull = 'frozen_full';

    public static function level(string $type): self
    {
        return match ($type) {
            'quarter_to_half' => self::FrozenQuarter,
            'half_to_full' => self::FrozenHalf,
            default => throw new InvalidArgumentException("Invalid consolidation level: {$type}")
        };
    }

    public function consolidationTarget(): self
    {
        return match ($this) {
            self::FrozenQuarter => self::FrozenHalf,
            self::FrozenHalf => self::FrozenFull,
            default => throw new InvalidArgumentException("Invalid source bundle type for consolidation: {$this->value}")
        };
    }
}
