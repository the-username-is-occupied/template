<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\NotebookLM\DTOs\ShareStatusDTO;

class ShareStatusDTOFactory
{
    public static function make(array $attributes = []): ShareStatusDTO
    {
        return ShareStatusDTO::from(array_merge([
            'notebook_id' => fake()->uuid(),
            'is_public' => false,
            'access' => 'private',
            'view_level' => 'owner',
            'shared_users' => [],
            'share_url' => null,
        ], $attributes));
    }
}
