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
        $sequentialMap = $this->buildSequentialNumberMap($links);

        return preg_replace_callback(
            self::FOOTNOTE_PATTERN,
            fn (array $matches) => $this->renderFootnoteGroup($matches[1], $sequentialMap, $links),
            $text
        );
    }

    /**
     * Строит карту "исходный ключ ссылки" -> "последовательный номер".
     * Дублирующиеся ссылки (ведущие на один URL) получают тот же
     * последовательный номер, что и первое вхождение.
     * Номера идут по порядку: 1, 2, 3, ...
     *
     * @param  array<int, string>  $links
     * @return array<int, int>
     */
    private function buildSequentialNumberMap(array $links): array
    {
        $firstKeyByUrl = [];
        $sequentialNumber = 0;
        $keyMap = [];

        foreach ($links as $key => $url) {
            if (! isset($firstKeyByUrl[$url])) {
                $firstKeyByUrl[$url] = ++$sequentialNumber;
            }
            $keyMap[$key] = $firstKeyByUrl[$url];
        }

        return $keyMap;
    }

    /**
     * @param  array<int, int>  $keyMap  original_key -> sequential_number
     * @param  array<int, string>  $links  original_key -> url
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

        // Map original numbers to sequential numbers, then get unique ones
        $mappedNumbers = array_values(array_unique(array_map(
            static fn (int $num) => $keyMap[$num] ?? $num,
            $numbers
        )));

        // Build reverse map: sequential_number -> url
        $sequentialLinks = $this->buildSequentialLinks($links, $keyMap);

        $replacement = array_map(
            static fn (int $seqNum) => isset($sequentialLinks[$seqNum])
                ? "[{$seqNum}]({$sequentialLinks[$seqNum]})"
                : "[{$seqNum}]",
            $mappedNumbers
        );

        return implode(', ', $replacement);
    }

    /**
     * Строит массив "последовательный номер" -> "URL"
     * на основе карты маппинга.
     *
     * @param  array<int, string>  $links
     * @param  array<int, int>  $keyMap
     * @return array<int, string>
     */
    private function buildSequentialLinks(array $links, array $keyMap): array
    {
        $sequentialLinks = [];
        foreach ($links as $originalKey => $url) {
            $seqNum = $keyMap[$originalKey] ?? $originalKey;
            if (! isset($sequentialLinks[$seqNum])) {
                $sequentialLinks[$seqNum] = $url;
            }
        }

        return $sequentialLinks;
    }

    /**
     * Возвращает ссылки с последовательными номерами (1, 2, 3, ...).
     * Дублирующиеся URL игнорируются (оставляется первое вхождение).
     * Использует ту же логику дедупликации, что и linkFootnotes(),
     * чтобы список источников совпадал с тем, что реально пронумеровано в тексте.
     *
     * @param  array<int, string>  $links
     * @return array<int, string> sequential_number => url
     */
    public function deduplicateLinks(array $links): array
    {
        $keyMap = $this->buildSequentialNumberMap($links);
        $sequentialLinks = $this->buildSequentialLinks($links, $keyMap);

        // Keep only the first occurrence for each URL (lowest sequential number)
        $uniqueLinks = [];
        $seenUrls = [];
        foreach ($keyMap as $originalKey => $seqNum) {
            $url = $links[$originalKey];
            if (! in_array($url, $seenUrls, true)) {
                $seenUrls[] = $url;
                $uniqueLinks[$seqNum] = $url;
            }
        }

        return $uniqueLinks;
    }

    /**
     * Возвращает карту маппинга "исходный номер цитаты" -> "последовательный номер".
     * Используется в TelegramCitationsFormatter для сопоставления
     * citation_number с последовательными номерами ссылок в тексте.
     *
     * @param  array<int, string>  $links
     * @return array<int, int> original_number => sequential_number
     */
    public function getSequentialMap(array $links): array
    {
        return $this->buildSequentialNumberMap($links);
    }
}
