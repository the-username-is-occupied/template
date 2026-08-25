<?php

declare(strict_types=1);

namespace App\Enums;

enum TechAccountPoolType: string
{
    case Free = 'free';
    case Plus = 'plus';
    case Pro = 'pro';
    case Ultra = 'ultra';

    public function label(): string
    {
        return ucfirst($this->value);
    }

    public function price(): string
    {
        return match ($this) {
            self::Free => '0$ per month',
            self::Plus => '10$ per month',
            self::Pro => '20$ per month',
            self::Ultra => '100$ per month',
        };
    }

    public function notebooksLimit(): array
    {
        return match ($this) {
            self::Free => [
                'tier' => 'free',
                'notebooks_limit' => 100,
                'sources_per_notebook' => 50,
                'chats_per_day' => 50,
                'audio_per_day' => 3,
            ],
            self::Plus => [
                'tier' => 'plus',
                'notebooks_limit' => 200,
                'sources_per_notebook' => 100,
                'chats_per_day' => 200,
                'audio_per_day' => 6,
            ],
            self::Pro => [
                'tier' => 'pro',
                'notebooks_limit' => 500,
                'sources_per_notebook' => 300,
                'chats_per_day' => 500,
                'audio_per_day' => 20,
            ],
            self::Ultra => [
                'tier' => 'ultra',
                'notebooks_limit' => 500,
                'sources_per_notebook' => 500,
                'chats_per_day' => 2500,
                'audio_per_day' => 100,
            ],
        };
    }
}
