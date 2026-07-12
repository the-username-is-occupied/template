<?php

declare(strict_types=1);

namespace App\Services\TelegramBot\Formatting;

/**
 * Режет уже экранированный MarkdownV2-текст на куски заданной длины,
 * не разрывая markdown-сущности (**, __, _, ~, ||, ```) и не обрезая
 * посреди экранированного символа ("\." и т.п.).
 *
 * Каждый кусок, кроме первого, получает префикс продолжения.
 * При необходимости под каждый кусок можно зарезервировать место
 * под суффикс, который будет приклеен к нему позже (например, подпись).
 */
final class TelegramMessageSplitter
{
    private const TOGGLE_TOKENS = ['```', '__', '||', '*', '_', '~'];

    private const TOKEN_PATTERN = '/(```|__|\*|_|~|\|\||\[[^\]]+\]\([^)]+\)|\\\\.|.)/us';

    /**
     * @return array<int, string>
     */
    public function split(
        string $text,
        int $maxLength,
        string $continuationPrefix,
        int $reservedSuffixLength = 0,
    ): array {
        preg_match_all(self::TOKEN_PATTERN, $text, $matches);
        $tokens = $matches[0];
        $totalTokens = count($tokens);

        $chunks = [];
        $chunkTokens = [];
        $stack = [];
        $stackAtStart = [];

        $lastNewlineIndexInChunk = -1;
        $lastNewlineStack = [];

        $isSubsequent = false;
        $i = 0;

        while ($i < $totalTokens) {
            $token = $tokens[$i];

            $currentLength = $isSubsequent ? mb_strlen($continuationPrefix) : 0;
            foreach ($stackAtStart as $t) {
                $currentLength += mb_strlen($t);
            }
            foreach ($chunkTokens as $ct) {
                $currentLength += mb_strlen($ct);
            }

            $tempStack = $this->toggle($stack, $token, false);
            $closingLen = array_sum(array_map('mb_strlen', $tempStack));

            $wouldOverflow = $currentLength + mb_strlen($token) + $closingLen + $reservedSuffixLength > $maxLength;

            if ($wouldOverflow) {
                if (empty($chunkTokens)) {
                    // Одиночный токен уже длиннее лимита — деваться некуда, кладём как есть.
                    $chunkTokens[] = $token;
                    $stack = $this->toggle($stack, $token, true);
                    if ($token === "\n") {
                        $lastNewlineIndexInChunk = count($chunkTokens) - 1;
                        $lastNewlineStack = $stack;
                    }
                    $i++;

                    continue;
                }

                if ($lastNewlineIndexInChunk !== -1) {
                    [$chunks, $chunkTokens, $stack, $i] = $this->flushAtLastNewline(
                        $chunks,
                        $chunkTokens,
                        $stackAtStart,
                        $lastNewlineIndexInChunk,
                        $lastNewlineStack,
                        $isSubsequent,
                        $continuationPrefix,
                        $i
                    );
                    $stackAtStart = $stack;
                    $lastNewlineIndexInChunk = -1;
                    $lastNewlineStack = [];
                    $isSubsequent = true;

                    continue;
                }

                $chunks[] = $this->renderChunk($chunkTokens, $stackAtStart, $stack, $isSubsequent, $continuationPrefix);
                $chunkTokens = [];
                $stackAtStart = $stack;
                $isSubsequent = true;

                continue;
            }

            $chunkTokens[] = $token;
            $stack = $this->toggle($stack, $token, true);

            if ($token === "\n") {
                $lastNewlineIndexInChunk = count($chunkTokens) - 1;
                $lastNewlineStack = $stack;
            }

            $i++;
        }

        if (! empty($chunkTokens)) {
            $chunks[] = $this->renderChunk($chunkTokens, $stackAtStart, $stack, $isSubsequent, $continuationPrefix);
        }

        return $chunks;
    }

    /**
     * @param  array<int, string>  $stack
     * @return array<int, string>
     */
    private function toggle(array $stack, string $token, bool $mutateStackForReal): array
    {
        if (! in_array($token, self::TOGGLE_TOKENS, true)) {
            return $stack;
        }

        if (! empty($stack) && end($stack) === $token) {
            array_pop($stack);
        } else {
            $stack[] = $token;
        }

        return $stack;
    }

    /**
     * @param  array<int, string>  $chunkTokens
     * @param  array<int, string>  $stackAtStart
     * @param  array<int, string>  $stack
     */
    private function renderChunk(
        array $chunkTokens,
        array $stackAtStart,
        array $stack,
        bool $isSubsequent,
        string $continuationPrefix,
    ): string {
        $chunkText = $isSubsequent ? $continuationPrefix : '';
        $chunkText .= implode('', $stackAtStart);
        $chunkText .= implode('', $chunkTokens);
        $chunkText .= implode('', array_reverse($stack));

        return $chunkText;
    }

    /**
     * Откатывается до последнего перевода строки внутри текущего куска,
     * чтобы не резать посреди слова/строки без необходимости.
     *
     * @param  array<int, string>  $chunks
     * @param  array<int, string>  $chunkTokens
     * @param  array<int, string>  $stackAtStart
     * @param  array<int, string>  $lastNewlineStack
     * @return array{0: array<int, string>, 1: array<int, string>, 2: array<int, string>, 3: int}
     */
    private function flushAtLastNewline(
        array $chunks,
        array $chunkTokens,
        array $stackAtStart,
        int $lastNewlineIndexInChunk,
        array $lastNewlineStack,
        bool $isSubsequent,
        string $continuationPrefix,
        int $currentIndex,
    ): array {
        $savedTokens = array_slice($chunkTokens, 0, $lastNewlineIndexInChunk + 1);

        $chunks[] = $this->renderChunk($savedTokens, $stackAtStart, $lastNewlineStack, $isSubsequent, $continuationPrefix);

        $discardedCount = count($chunkTokens) - ($lastNewlineIndexInChunk + 1);

        return [$chunks, [], $lastNewlineStack, $currentIndex - $discardedCount];
    }
}
