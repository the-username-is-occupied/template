<?php

declare(strict_types=1);

namespace App\Share\Enums\Traits;

trait HasBaseEnum
{
    public function toArray(): array
    {
        return [
            'value' => $this->value,
            'label' => $this->label(),
        ];
    }

    public static function values(): array
    {
        return array_column(static::cases(), 'value');
    }

    public static function random(): static
    {
        return static::cases()[array_rand(static::cases())];
    }

    public function is($enum): bool
    {
        return $this === $enum;
    }

    public function any(array $enums): bool
    {
        return array_any($enums, fn ($enum): bool => $this === $enum);
    }

    public static function except(array $cases): array
    {
        return array_filter(static::cases(), fn ($enum): bool => ! in_array($enum, $cases, true));
    }
}
