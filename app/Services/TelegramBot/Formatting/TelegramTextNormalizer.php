<?php

declare(strict_types=1);

namespace App\Services\TelegramBot\Formatting;

/**
 * Приводит "сырой" markdown от модели к виду, пригодному для дальнейшего
 * экранирования: убирает случайные отступы, превращает заголовки и списки
 * в сущности, понятные Telegram MarkdownV2.
 */
final class TelegramTextNormalizer
{
    public function trimLines(string $text): string
    {
        $lines = array_map(
            static fn (string $line) => ltrim($line, " \t"),
            explode("\n", $text)
        );

        return implode("\n", $lines);
    }

    /**
     * "### Заголовок" -> "**Заголовок**"
     */
    public function headersToBold(string $text): string
    {
        return preg_replace_callback(
            '/###\s*(.+)/u',
            static fn (array $matches) => '**'.$matches[1]."**\n",
            $text
        );
    }

    /**
     * "* пункт списка" -> "◦ пункт списка"
     */
    public function bulletsToDots(string $text): string
    {
        return preg_replace('/^\s*\*\s+/um', '◦ ', $text);
    }
}
