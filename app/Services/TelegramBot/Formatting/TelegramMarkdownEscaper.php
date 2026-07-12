<?php

declare(strict_types=1);

namespace App\Services\TelegramBot\Formatting;

/**
 * Экранирует произвольный текст под правила Telegram MarkdownV2,
 * не трогая содержимое уже оформленных markdown-сущностей
 * (жирный, курсив, код, спойлер, ссылки) — только их внутренности.
 */
final class TelegramMarkdownEscaper
{
    /**
     * Захватывает целиком блоки: код, жирный/курсив/зачёркнутый/спойлер, ссылки.
     * Всё остальное считается обычным текстом и экранируется полностью.
     */
    private const ENTITY_PATTERN = '/(```.*?```|`.*?`|\*\*.*?\*\*|__.*?__|_.*?_|~.*?~|\|\|.*?\|\||\[[^\]]+\]\([^)]+\))/us';

    private const CHARS_TO_ESCAPE = ['_', '*', '[', ']', '(', ')', '~', '`', '>', '#', '+', '-', '=', '|', '{', '}', '.', '!'];

    private const CODE_ESCAPE_MAP = ['`' => '\`', '\\' => '\\\\'];

    private const URL_ESCAPE_MAP = [')' => '\)', '\\' => '\\\\'];

    /** @var array<string, string> */
    private array $textEscapeMap;

    public function __construct()
    {
        $this->textEscapeMap = array_combine(
            self::CHARS_TO_ESCAPE,
            array_map(static fn (string $char) => '\\'.$char, self::CHARS_TO_ESCAPE)
        );
    }

    public function escape(string $text): string
    {
        $parts = preg_split(self::ENTITY_PATTERN, $text, -1, PREG_SPLIT_DELIM_CAPTURE);

        if ($parts === false) {
            return strtr($text, $this->textEscapeMap);
        }

        foreach ($parts as &$part) {
            $part = $this->escapePart($part);
        }
        unset($part);

        return implode('', $parts);
    }

    private function escapePart(string $part): string
    {
        if (! preg_match(self::ENTITY_PATTERN, $part)) {
            return strtr($part, $this->textEscapeMap);
        }

        return match (true) {
            str_starts_with($part, '```') && str_ends_with($part, '```') => $this->wrapCode($part, '```', 3),
            str_starts_with($part, '`') && str_ends_with($part, '`') => $this->wrapCode($part, '`', 1),
            str_starts_with($part, '**') && str_ends_with($part, '**') => $this->wrapText($part, '**', 2),
            str_starts_with($part, '__') && str_ends_with($part, '__') => $this->wrapText($part, '__', 2),
            str_starts_with($part, '_') && str_ends_with($part, '_') => $this->wrapText($part, '_', 1),
            str_starts_with($part, '~') && str_ends_with($part, '~') => $this->wrapText($part, '~', 1),
            str_starts_with($part, '||') && str_ends_with($part, '||') => $this->wrapText($part, '||', 2),
            str_starts_with($part, '[') && str_ends_with($part, ')') => $this->wrapLink($part),
            default => $part,
        };
    }

    private function wrapCode(string $part, string $delimiter, int $delimiterLength): string
    {
        $inner = substr($part, $delimiterLength, -$delimiterLength);

        return $delimiter.strtr($inner, self::CODE_ESCAPE_MAP).$delimiter;
    }

    private function wrapText(string $part, string $delimiter, int $delimiterLength): string
    {
        $inner = substr($part, $delimiterLength, -$delimiterLength);

        // __ — единственная сущность, чьи разделители Telegram не требует экранировать одинаково с * и _
        return $delimiter.strtr($inner, $this->textEscapeMap).$delimiter;
    }

    private function wrapLink(string $part): string
    {
        if (! preg_match('/^\[(.*)\]\((.*)\)$/us', $part, $matches)) {
            return $part;
        }

        $linkText = strtr($matches[1], $this->textEscapeMap);
        $linkUrl = strtr($matches[2], self::URL_ESCAPE_MAP);

        return '['.$linkText.']('.$linkUrl.')';
    }
}
