<?php

declare(strict_types=1);

namespace App\Services\TelegramBot\Formatting;

use Spatie\LaravelData\DataCollection;

/**
 * Формирует HTML-сообщение со списком источников (цитат) для Telegram.
 * Список строится только из тех citation_number, что остаются после
 * дедупликации ссылок — то есть ровно те номера, что фактически
 * встречаются как сноски в тексте ответа.
 *
 * Номера в списке идут последовательно (1, 2, 3...) после дедупликации.
 */
final class TelegramCitationsFormatter
{
    public function __construct(
        private readonly TelegramFootnoteLinker $footnoteLinker,
    ) {}

    /**
     * @param  DataCollection<int, mixed>  $citations  DTO с полями
     *                                                 citation_number, title, source_type, source_url и методом typeLabel()
     * @param  array<int, string>  $links  citation_number => source_url
     */
    public function format(DataCollection $citations, array $links): ?string
    {
        $uniqueLinks = $this->footnoteLinker->deduplicateLinks($links);

        if ($uniqueLinks === []) {
            return null;
        }

        // Build map: original_citation_number -> sequential_number
        $sequentialMap = $this->footnoteLinker->getSequentialMap($links);

        $rows = [];
        $addedSequentialNumbers = [];
        foreach ($citations as $citation) {
            $originalNum = $citation->citation_number;
            if (! isset($sequentialMap[$originalNum])) {
                continue;
            }

            $sequentialNum = $sequentialMap[$originalNum];

            // Skip if this sequential number already added (deduplication)
            if (in_array($sequentialNum, $addedSequentialNumbers, true)) {
                continue;
            }

            $addedSequentialNumbers[] = $sequentialNum;
            $rows[] = $this->formatRow($citation, $sequentialNum);
        }

        if ($rows === []) {
            return null;
        }

        return '<blockquote expandable>'.implode("\n", $rows).'</blockquote>';
    }

    private function formatRow(mixed $citation, int $sequentialNum): string
    {
        $title = $citation->source_type === 'telegram_channel'
            ? $citation->title.'...'
            : $citation->title;

        return sprintf(
            '%d. <a href="%s">%s</a> %s',
            $sequentialNum,
            htmlspecialchars($citation->source_url),
            htmlspecialchars($title),
            htmlspecialchars($citation->typeLabel())
        );
    }
}
