<?php

declare(strict_types=1);

namespace App\Services\TelegramBot\Formatting;

/**
 * Режет "сырой" (неэкранированный) markdown на куски заданной длины,
 * стараясь резать по границам абзацев/строк, а не посреди слова.
 *
 * Каждый кусок предполагается независимо прогнать через
 * markdown -> html конвертер, поэтому здесь не нужно заботиться
 * о парности markdown-токенов (**, _, и т.п.) — CommonMark
 * прекрасно переживёт "оборванный" токен в отдельном куске.
 */
final class MarkdownSplitter
{
    /**
     * @return array<int, string>
     */
    public function split(
        string $text,
        int $maxLength,
        string $continuationPrefix = '',
        int $reservedSuffixLength = 0,
    ): array {
        // делим на блоки по пустым строкам — абзацы, элементы списка, заголовки и т.п.
        $blocks = preg_split('/\n{2,}/u', trim($text));

        $chunks = [];
        $current = '';
        $isSubsequent = false;

        foreach ($blocks as $block) {
            $prefixLen = $isSubsequent ? mb_strlen($continuationPrefix) : 0;
            $budget = $maxLength - $prefixLen - $reservedSuffixLength;

            $candidate = $current === '' ? $block : $current."\n\n".$block;

            if (mb_strlen($candidate) <= $budget) {
                $current = $candidate;

                continue;
            }

            // текущий блок не влезает вместе с накопленным — сбрасываем накопленное
            if ($current !== '') {
                $chunks[] = $this->finalize($current, $isSubsequent, $continuationPrefix);
                $isSubsequent = true;
                $current = '';
            }

            // сам блок может быть больше лимита (длинный список, длинный абзац) — режем построчно
            if (mb_strlen($block) > $budget) {
                $pieces = $this->splitLargeBlock($block, $maxLength, $continuationPrefix, $reservedSuffixLength, $isSubsequent);
                foreach ($pieces as $piece) {
                    $chunks[] = $piece;
                    $isSubsequent = true;
                }

                continue;
            }

            $current = $block;
        }

        if ($current !== '') {
            $chunks[] = $this->finalize($current, $isSubsequent, $continuationPrefix);
        }

        return $chunks;
    }

    /**
     * Режет один слишком большой блок построчно (по \n), а если и отдельная
     * строка не влезает — режет её жёстко по символам (крайний случай).
     *
     * @return array<int, string>
     */
    private function splitLargeBlock(
        string $block,
        int $maxLength,
        string $continuationPrefix,
        int $reservedSuffixLength,
        bool $isSubsequent,
    ): array {
        $lines = explode("\n", $block);
        $chunks = [];
        $current = '';

        foreach ($lines as $line) {
            $prefixLen = $isSubsequent ? mb_strlen($continuationPrefix) : 0;
            $budget = $maxLength - $prefixLen - $reservedSuffixLength;

            $candidate = $current === '' ? $line : $current."\n".$line;

            if (mb_strlen($candidate) <= $budget) {
                $current = $candidate;

                continue;
            }

            if ($current !== '') {
                $chunks[] = $this->finalize($current, $isSubsequent, $continuationPrefix);
                $isSubsequent = true;
                $current = '';
            }

            if (mb_strlen($line) > $budget) {
                // крайний случай: одна строка длиннее лимита — режем жёстко по символам
                foreach ($this->hardSplit($line, $maxLength, $continuationPrefix, $reservedSuffixLength, $isSubsequent) as $piece) {
                    $chunks[] = $piece;
                    $isSubsequent = true;
                }

                continue;
            }

            $current = $line;
        }

        if ($current !== '') {
            $chunks[] = $this->finalize($current, $isSubsequent, $continuationPrefix);
        }

        return $chunks;
    }

    /**
     * @return array<int, string>
     */
    private function hardSplit(
        string $line,
        int $maxLength,
        string $continuationPrefix,
        int $reservedSuffixLength,
        bool $isSubsequent,
    ): array {
        $chunks = [];

        while ($line !== '') {
            $prefixLen = $isSubsequent ? mb_strlen($continuationPrefix) : 0;
            $budget = max(1, $maxLength - $prefixLen - $reservedSuffixLength);

            $piece = mb_substr($line, 0, $budget);
            $line = mb_substr($line, mb_strlen($piece));

            $chunks[] = $this->finalize($piece, $isSubsequent, $continuationPrefix);
            $isSubsequent = true;
        }

        return $chunks;
    }

    private function finalize(string $text, bool $isSubsequent, string $continuationPrefix): string
    {
        return $isSubsequent ? $continuationPrefix.$text : $text;
    }
}
