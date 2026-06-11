<?php

namespace App\Domain\DTOs\TG;

use Spatie\LaravelData\Data;

class PostResponse extends Data
{
    public function __construct(
        public int $id,
        public string $text,
        public ?string $date,
        public ?array $media,
        public ?array $reactions,
        public ?array $poll,
        public ?string $author,
        public ?string $link
    ) {}
}
