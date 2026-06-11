<?php

declare(strict_types=1);

namespace App\Domain\Telegram\DTOs;

use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\DataCollection;

class TelegramPostDTO extends Data
{
    public function __construct(
        public int $id,
        public string $url,
        public ?string $date,
        public string $text,
        public string $text_html,
        public array $links,
        public ?string $views,
        public string $type,
        public ?array $forwarded_from,
        public ?array $poll,
        #[DataCollectionOf(TelegramReactionDTO::class)]
        public ?DataCollection $reactions,
    ) {}
}
