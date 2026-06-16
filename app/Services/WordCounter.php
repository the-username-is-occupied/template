<?php

declare(strict_types=1);

namespace App\Services;

class WordCounter
{
    /**
     * Count words in a text, supporting Cyrillic and Latin characters.
     */
    public function count(string $text): int
    {
        $text = strip_tags($text);

        return (int) preg_match_all('/[\p{L}\p{N}]+/u', $text);
    }
}
