<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\NotebookLM\DTOs\HealthStatusDTO;

class HealthStatusDTOFactory
{
    public static function make(array $attributes = []): HealthStatusDTO
    {
        return HealthStatusDTO::from(array_merge([
            'account_id' => fake()->uuid(),
            'mtime_age_seconds' => fake()->numberBetween(0, 3600),
            'mtime' => fake()->dateTime()->format('Y-m-d H:i:s'),
            'is_connected' => true,
            'status' => 'healthy',
        ], $attributes));
    }
}
