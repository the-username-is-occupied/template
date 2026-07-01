<?php

declare(strict_types=1);

namespace App\Services\TelegramBot;

use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;

class TelegramMessageFormatterService
{
    private const MAX_MESSAGE_LENGTH = 4090;

    private const CONTINUATION_PREFIX = "_\(к предыдущему сообщению\)_\n";

    private const AI_SIGNATURE = "\n\n _Ответы AI могут быть неточны. Обязательно проверяйте их._";

    /**
     * Format the answer text with citations and prepare for Telegram
     */
    public function formatAnswer(string $text, array $links = []): string
    {
        return $this->prepareTelegramMarkdown($text, $links);
    }

    /**
     * Format suggested questions as italic text
     *
     * @param  array<int, string>  $questions
     */
    public function formatSuggestedQuestions(array $questions): string
    {
        $formattedQuestions = array_map(fn (string $item) => "_{$item}_", $questions);

        return $this->prepareTelegramMarkdown("\n\n".implode("\n", $formattedQuestions));
    }

    /**
     * Create an inline keyboard for suggested questions
     *
     * @param  array<int, string>  $questions
     */
    public function createSuggestedQuestionsKeyboard(string $messageId, array $questions): InlineKeyboardMarkup
    {
        $buttons = array_map(
            fn (int $index) => InlineKeyboardButton::make(
                (string) $index,
                callback_data: 'ask:'.$messageId.':'.$index
            ),
            range(1, min(3, count($questions)))
        );

        return InlineKeyboardMarkup::make()->addRow(...$buttons);
    }

    /**
     * Split a long markdown message into chunks that fit Telegram's limits
     *
     * @return array<int, string>
     */
    public function splitLongMessage(string $text): array
    {
        return $this->splitTelegramMarkdown($text, self::MAX_MESSAGE_LENGTH);
    }

    /**
     * Prepare the complete message with signature
     */
    public function prepareCompleteMessage(string $text, array $links = []): string
    {
        return $this->prepareTelegramMarkdown($text, $links);
    }

    /**
     * Prepare text formatting specifically for Telegram MarkdownV2
     */
    private function prepareTelegramMarkdown(string $text, array $links = []): string
    {
        // Схлопываем дубликаты URL в массиве сносок и строим карту соответствия старых ключей к новым уникальным
        $uniqueUrls = [];
        $keyMap = [];
        foreach ($links as $key => $url) {
            if (! isset($uniqueUrls[$url])) {
                $uniqueUrls[$url] = $key;
            }
            $keyMap[$key] = $uniqueUrls[$url];
        }

        // 1. Очищаем лишние отступы слева в каждой строке
        $lines = explode("\n", $text);
        $lines = array_map(fn (string $line) => ltrim($line, " \t"), $lines);
        $text = implode("\n", $lines);

        // 2. Превращаем заголовки "### Название" в жирный текст
        $text = preg_replace_callback('/###\s*(.+)/u', function (array $matches) {
            return '**'.$matches[1]."**\n";
        }, $text);

        // 3. Разбираем квадратные скобки со сносками (поддерживает [24, 28-30] и дедуплицирует одинаковые ссылки)
        $text = preg_replace_callback('/\[([0-9\s,\-]+)\]/u', function (array $matches) use ($keyMap, $links) {
            $rawInside = $matches[1];
            $parts = array_map('trim', explode(',', $rawInside));
            $numbers = [];

            foreach ($parts as $part) {
                if (str_contains($part, '-')) {
                    [$start, $end] = array_map('intval', explode('-', $part));
                    if ($start <= $end) {
                        $numbers = array_merge($numbers, range($start, $end));
                    }
                } else {
                    $numbers[] = (int) $part;
                }
            }

            // Маппим каждый номер сноски на его первородный уникальный ID ссылки
            $mappedNumbers = array_map(fn (int $num) => $keyMap[$num] ?? $num, $numbers);
            // Удаляем повторения внутри одной группы квадратных скобок (например, преобразуем [1, 1] в [1])
            $mappedNumbers = array_unique($mappedNumbers);

            $replacement = [];
            foreach ($mappedNumbers as $num) {
                if (isset($links[$num])) {
                    $replacement[] = "[{$num}]({$links[$num]})";
                } else {
                    $replacement[] = "[{$num}]";
                }
            }

            return implode(', ', $replacement);
        }, $text);

        // 4. Заменяем одиночные звездочки списков на аккуратный буллит "◦ "
        $text = preg_replace('/^\s*\*\s+/um', '◦ ', $text);

        // 5. Разрезаем текст для безопасного экранирования
        $pattern = '/(```.*?```|`.*?`|\*\*.*?\*\*|__.*?__|_.*?_|~.*?~|\|\|.*?\|\||\[[^\]]+\]\([^)]+\))/us';
        $parts = preg_split($pattern, $text, -1, PREG_SPLIT_DELIM_CAPTURE);

        // Стандартная карта экранирования Telegram MarkdownV2
        $charsToEscape = ['_', '*', '[', ']', '(', ')', '~', '`', '>', '#', '+', '-', '=', '|', '{', '}', '.', '!'];
        $escapedMapping = [];
        foreach ($charsToEscape as $char) {
            $escapedMapping[$char] = '\\'.$char;
        }

        $codeEscapedMapping = ['`' => '\`', '\\' => '\\\\'];
        $urlEscapedMapping = [')' => '\)', '\\' => '\\\\'];

        foreach ($parts as &$part) {
            if (! preg_match($pattern, $part)) {
                $part = strtr($part, $escapedMapping);

                continue;
            }

            if (str_starts_with($part, '```') && str_ends_with($part, '```')) {
                $innerContent = substr($part, 3, -3);
                $part = '```'.strtr($innerContent, $codeEscapedMapping).'```';

            } elseif (str_starts_with($part, '`') && str_ends_with($part, '`')) {
                $innerContent = substr($part, 1, -1);
                $part = '`'.strtr($innerContent, $codeEscapedMapping).'`';

            } elseif (str_starts_with($part, '**') && str_ends_with($part, '**')) {
                $innerContent = substr($part, 2, -2);
                $part = '*'.strtr($innerContent, $escapedMapping).'*';

            } elseif (str_starts_with($part, '__') && str_ends_with($part, '__')) {
                $innerContent = substr($part, 2, -2);
                $part = '__'.strtr($innerContent, $escapedMapping).'__';

            } elseif (str_starts_with($part, '_') && str_ends_with($part, '_')) {
                $innerContent = substr($part, 1, -1);
                $part = '_'.strtr($innerContent, $escapedMapping).'_';

            } elseif (str_starts_with($part, '~') && str_ends_with($part, '~')) {
                $innerContent = substr($part, 1, -1);
                $part = '~'.strtr($innerContent, $escapedMapping).'~';

            } elseif (str_starts_with($part, '||') && str_ends_with($part, '||')) {
                $innerContent = substr($part, 2, -2);
                $part = '||'.strtr($innerContent, $escapedMapping).'||';

            } elseif (str_starts_with($part, '[') && str_ends_with($part, ')')) {
                if (preg_match('/^\[(.*)\]\((.*)\)$/us', $part, $linkMatches)) {
                    $linkText = strtr($linkMatches[1], $escapedMapping);
                    $linkUrl = strtr($linkMatches[2], $urlEscapedMapping);
                    $part = '['.$linkText.']('.$linkUrl.')';
                }
            }
        }
        unset($part);

        return implode('', $parts);
    }

    /**
     * Splits long markdown messages safely avoiding breaking markdown tags
     *
     * @return array<int, string>
     */
    private function splitTelegramMarkdown(string $text, int $maxLength = self::MAX_MESSAGE_LENGTH): array
    {
        $prefix = self::CONTINUATION_PREFIX;

        $pattern = '/(```|__|\*|_|~|\|\||\[[^\]]+\]\([^)]+\)|\\\\.|.)/us';
        preg_match_all($pattern, $text, $matches);
        $tokens = $matches[0];

        $chunks = [];
        $chunkTokens = [];
        $stack = [];
        $stackAtStart = [];

        $lastNewlineIndexInChunk = -1;
        $lastNewlineStack = [];

        $isSubsequent = false;
        $i = 0;
        $totalTokens = count($tokens);

        while ($i < $totalTokens) {
            $token = $tokens[$i];

            $currentLength = $isSubsequent ? mb_strlen($prefix) : 0;
            foreach ($stackAtStart as $t) {
                $currentLength += mb_strlen($t);
            }
            foreach ($chunkTokens as $ct) {
                $currentLength += mb_strlen($ct);
            }

            $tempStack = $stack;
            if (in_array($token, ['```', '__', '||', '*', '_', '~'])) {
                if (! empty($tempStack) && end($tempStack) === $token) {
                    array_pop($tempStack);
                } else {
                    $tempStack[] = $token;
                }
            }

            $closingLen = 0;
            foreach ($tempStack as $t) {
                $closingLen += mb_strlen($t);
            }

            if ($currentLength + mb_strlen($token) + $closingLen > $maxLength) {
                if (empty($chunkTokens)) {
                    $chunkTokens[] = $token;
                    if (in_array($token, ['```', '__', '||', '*', '_', '~'])) {
                        if (! empty($stack) && end($stack) === $token) {
                            array_pop($stack);
                        } else {
                            $stack[] = $token;
                        }
                    }
                    if ($token === "\n") {
                        $lastNewlineIndexInChunk = count($chunkTokens) - 1;
                        $lastNewlineStack = $stack;
                    }
                    $i++;

                    continue;
                }

                if ($lastNewlineIndexInChunk !== -1) {
                    $savedTokens = array_slice($chunkTokens, 0, $lastNewlineIndexInChunk + 1);

                    $chunkText = ($isSubsequent ? $prefix : '');
                    if (! empty($stackAtStart)) {
                        $chunkText .= implode('', $stackAtStart);
                    }
                    $chunkText .= implode('', $savedTokens);
                    if (! empty($lastNewlineStack)) {
                        $chunkText .= implode('', array_reverse($lastNewlineStack));
                    }
                    $chunks[] = $chunkText;

                    $discardedCount = count($chunkTokens) - ($lastNewlineIndexInChunk + 1);
                    $i = $i - $discardedCount;

                    $chunkTokens = [];
                    $stack = $lastNewlineStack;
                    $stackAtStart = $stack;
                    $lastNewlineIndexInChunk = -1;
                    $lastNewlineStack = [];
                    $isSubsequent = true;

                    continue;
                } else {
                    $chunkText = ($isSubsequent ? $prefix : '');
                    if (! empty($stackAtStart)) {
                        $chunkText .= implode('', $stackAtStart);
                    }
                    $chunkText .= implode('', $chunkTokens);
                    if (! empty($stack)) {
                        $chunkText .= implode('', array_reverse($stack));
                    }
                    $chunks[] = $chunkText;

                    $chunkTokens = [];
                    $stackAtStart = $stack;
                    $isSubsequent = true;

                    continue;
                }
            }

            $chunkTokens[] = $token;
            if (in_array($token, ['```', '__', '||', '*', '_', '~'])) {
                if (! empty($stack) && end($stack) === $token) {
                    array_pop($stack);
                } else {
                    $stack[] = $token;
                }
            }

            if ($token === "\n") {
                $lastNewlineIndexInChunk = count($chunkTokens) - 1;
                $lastNewlineStack = $stack;
            }

            $i++;
        }

        if (! empty($chunkTokens)) {
            $chunkText = ($isSubsequent ? $prefix : '');
            if (! empty($stackAtStart)) {
                $chunkText .= implode('', $stackAtStart);
            }
            $chunkText .= implode('', $chunkTokens);
            if (! empty($stack)) {
                $chunkText .= implode('', array_reverse($stack));
            }
            $chunks[] = $chunkText;
        }

        return $chunks;
    }
}
