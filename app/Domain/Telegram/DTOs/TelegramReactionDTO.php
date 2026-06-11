<?php

declare(strict_types=1);

namespace App\Domain\Telegram\DTOs;

use Spatie\LaravelData\Data;

class TelegramReactionDTO extends Data
{
    public function __construct(
        public string $emoji,
        public string $count,
    ) {}
}
