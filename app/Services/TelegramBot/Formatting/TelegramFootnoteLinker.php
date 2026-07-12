<?php

declare(strict_types=1);

namespace App\Services\TelegramBot\Formatting;

/**
 * Разбирает сноски вида "[24]" и "[24, 28-30]", дедуплицирует ссылки,
 * ведущие на один и тот же URL, и превращает номера в markdown-ссылки.
 */
final class TelegramFootnoteLinker
{
    private const FOOTNOTE_PATTERN = '/\[([0-9\s,\-]+)\]/u';

    /**
     * @param  array<int, string>  $links
     */
    public function linkFootnotes(string $text, array $links): string
    {
        $keyMap = $this->buildDeduplicatedKeyMap($links);

        return preg_replace_callback(
            self::FOOTNOTE_PATTERN,
            fn (array $matches) => $this->renderFootnoteGroup($matches[1], $keyMap, $links),
            $text
        );
    }

    /**
     * Строит карту "исходный ключ ссылки" -> "первый ключ с таким же URL",
     * чтобы дублирующиеся ссылки схлопывались в одну сноску.
     *
     * @param  array<int, string>  $links
     * @return array<int, int>
     */
    private function buildDeduplicatedKeyMap(array $links): array
    {
        $firstKeyByUrl = [];
        $keyMap = [];

        foreach ($links as $key => $url) {
            if (! isset($firstKeyByUrl[$url])) {
                $firstKeyByUrl[$url] = $key;
            }
            $keyMap[$key] = $firstKeyByUrl[$url];
        }

        return $keyMap;
    }

    /**
     * @param  array<int, int>  $keyMap
     * @param  array<int, string>  $links
     */
    private function renderFootnoteGroup(string $rawInside, array $keyMap, array $links): string
    {
        $numbers = [];

        foreach (array_map('trim', explode(',', $rawInside)) as $part) {
            if (str_contains($part, '-')) {
                [$start, $end] = array_map('intval', explode('-', $part));
                if ($start <= $end) {
                    $numbers = array_merge($numbers, range($start, $end));
                }

                continue;
            }

            $numbers[] = (int) $part;
        }

        $mappedNumbers = array_unique(array_map(
            static fn (int $num) => $keyMap[$num] ?? $num,
            $numbers
        ));

        $replacement = array_map(
            static fn (int $num) => isset($links[$num]) ? "[{$num}]({$links[$num]})" : "[{$num}]",
            $mappedNumbers
        );

        return implode(', ', $replacement);
    }

    /**
     * Возвращает только "канонические" ссылки — по одной на каждый
     * уникальный URL (первое вхождение), в порядке исходных ключей.
     * Использует ту же логику дедупликации, что и linkFootnotes(),
     * чтобы список источников совпадал с тем, что реально пронумеровано в тексте.
     *
     * @param  array<int, string>  $links
     * @return array<int, string>
     */
    public function deduplicateLinks(array $links): array
    {
        $keyMap = $this->buildDeduplicatedKeyMap($links);

        return array_filter(
            $links,
            static fn (string $url, int $key) => $keyMap[$key] === $key,
            ARRAY_FILTER_USE_BOTH
        );
    }
}
