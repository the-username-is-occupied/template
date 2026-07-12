<?php

declare(strict_types=1);

namespace App\Services\TelegramBot\Formatting;

use Spatie\LaravelData\DataCollection;

/**
 * Формирует HTML-сообщение со списком источников (цитат) для Telegram.
 * Список строится только из тех citation_number, что остаются после
 * дедупликации ссылок — то есть ровно те номера, что фактически
 * встречаются как сноски в тексте ответа.
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

        $rows = [];
        foreach ($citations as $citation) {
            if (! array_key_exists($citation->citation_number, $uniqueLinks)) {
                continue;
            }

            $rows[] = $this->formatRow($citation);
        }

        if ($rows === []) {
            return null;
        }

        return '<blockquote expandable>'.implode("\n", $rows).'</blockquote>';
    }

    private function formatRow(mixed $citation): string
    {
        $title = $citation->source_type === 'telegram_channel'
            ? $citation->title.'...'
            : $citation->title;

        return sprintf(
            '%d. <a href="%s">%s</a> %s',
            $citation->citation_number,
            htmlspecialchars($citation->source_url),
            htmlspecialchars($title),
            htmlspecialchars($citation->typeLabel())
        );
    }
}
