<?php

declare(strict_types=1);

namespace App\Services\TelegramBot;

use App\Domain\NotebookLM\DTOs\SuggestedTopicDTO;
use App\Services\TelegramBot\Formatting\MarkdownSplitter as TelegramMessageSplitter;
use App\Services\TelegramBot\Formatting\TelegramCitationsFormatter;
use App\Services\TelegramBot\Formatting\TelegramFootnoteLinker;
use App\Services\TelegramBot\Formatting\TelegramMarkdownEscaper;
use App\Services\TelegramBot\Formatting\TelegramSignatureBuilder;
use App\Services\TelegramBot\Formatting\TelegramTextNormalizer;
use DOMDocument;
// use App\Services\TelegramBot\Formatting\TelegramMessageSplitter;
use DOMElement;
use DOMText;
use DOMXPath;
use League\CommonMark\CommonMarkConverter;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;
use Spatie\LaravelData\DataCollection;

final class TelegramMessageFormatterService
{
    private const MAX_MESSAGE_LENGTH = 4090;

    private const CONTINUATION_PREFIX = "<i>(к предыдущему сообщению)</i>\n";

    public function __construct(
        private readonly TelegramTextNormalizer $normalizer,
        private readonly TelegramFootnoteLinker $footnoteLinker,
        private readonly TelegramMarkdownEscaper $escaper,
        private readonly TelegramMessageSplitter $splitter,
        private readonly TelegramSignatureBuilder $signatureBuilder,
        private readonly TelegramCitationsFormatter $citationsFormatter,
        private readonly CommonMarkConverter $converter,
    ) {}

    /**
     * Форматирует ответ с сносками под Telegram MarkdownV2 (без подписи).
     *
     * @param  array<int, string>  $links
     */
    public function formatAnswer(string $text, array $links = []): string
    {
        return $this->prepareTelegramMarkdown2($text, $links);
    }

    /**
     * Форматирует предложенные вопросы курсивом.
     *
     * @param  array<int, string>  $questions
     */
    public function formatSuggestedQuestions(array $questions): string
    {
        $formattedQuestions = array_map(static fn ($index, string $item): string => '_'.($index + 1).'. '.$item.'_', array_keys($questions), $questions);

        return $this->prepareTelegramMarkdown2("\n\n".implode("\n", $formattedQuestions));
    }

    /**
     * Инлайн-клавиатура для предложенных вопросов из ChatMessage.
     *
     * @param  array<int, string>  $questions
     */
    public function createSuggestedQuestionsKeyboard(string $messageId, array $questions): InlineKeyboardMarkup
    {
        $buttons = array_map(
            static fn (int $index): InlineKeyboardButton => InlineKeyboardButton::make(
                (string) $index,
                callback_data: 'ask:'.$messageId.':'.$index
            ),
            range(1, min(4, count($questions)))
        );

        return InlineKeyboardMarkup::make()->addRow(...$buttons);
    }

    /**
     * Инлайн-клавиатура для предложенных тем из описания Notebook.
     *
     * @param  array<int, SuggestedTopicDTO>  $topics
     */
    public function createDescriptionQuestionsKeyboard(string $notebookId, array $topics): InlineKeyboardMarkup
    {
        $buttons = array_map(
            static fn (int $index): InlineKeyboardButton => InlineKeyboardButton::make(
                (string) ($index + 1),
                callback_data: 'ask_desc:'.$notebookId.':'.($index + 1)
            ),
            range(0, min(2, count($topics) - 1))
        );

        return InlineKeyboardMarkup::make()->addRow(...$buttons);
    }

    /**
     * Форматирует предложенные темы из описания Notebook курсивом, с нумерацией.
     *
     * @param  array<int, SuggestedTopicDTO>  $topics
     */
    public function formatDescriptionQuestions(array $topics): string
    {
        $formatted = [];
        foreach ($topics as $index => $topic) {
            $num = $index + 1;
            $formatted[] = "_{$num}. {$topic->question}_";
        }

        return $this->prepareTelegramMarkdown2("\n\n".implode("\n", $formatted));
    }

    /**
     * Готовит ответ модели к отправке: форматирует, режет на куски,
     * укладывающиеся в лимит Telegram, и приклеивает подпись
     * ("Ответы AI могут быть неточны..." + ссылка на бота) к КАЖДОМУ куску.
     *
     * @param  array<int, string>  $links
     * @return array<int, string> готовые к последовательной отправке сообщения
     */
    public function prepareCompleteMessage(string $text, array $links = []): array
    {

        $formatted = $this->prepareTelegramMarkdown($text, $links);
        $signature = $this->signatureBuilder->build();

        $chunks = $this->splitter->split(
            $formatted,
            self::MAX_MESSAGE_LENGTH,
            self::CONTINUATION_PREFIX,
            mb_strlen($signature)
        );

        return collect($chunks)
            ->map(function (string $chunk) use ($signature): string {

                return $this->convertToHtml($chunk.$signature);
            })
            ->toArray();
    }

    // public function convertToHtml(string $text): string
    // {
    //      $allowed = '<b><strong><i><em><u><s><del><code><pre><a><span><tg-spoiler>';
    //      $html = (string)$this->converter->convert($text);
    //      return $html;
    //             $html = preg_replace('/<\/p>\s*/', "\n\n",  $html);
    //             $html = str_replace('<p>', "", $html);
    //             $html = strip_tags($html, $allowed);
    //             $html = preg_replace('/\n{3,}/', "\n\n", $html);

    //             return trim($html);
    // }

    /**
     * Низкоуровневая утилита: режет уже экранированный markdown-текст
     * на куски без добавления подписи.
     *
     * @return array<int, string>
     */
    public function splitLongMessage(string $text): array
    {
        return $this->splitter->split($text, self::MAX_MESSAGE_LENGTH, self::CONTINUATION_PREFIX);
    }

    /**
     * @param  array<int, string>  $links
     */
    private function prepareTelegramMarkdown(string $text, array $links = []): string
    {

        return $this->footnoteLinker->linkFootnotes($text, $links);
    }

    /**
     * @param  array<int, string>  $links
     */
    private function prepareTelegramMarkdown2(string $text, array $links = []): string
    {

        $text = $this->normalizer->normalize(
            $text,
            fn (string $t): string => $this->footnoteLinker->linkFootnotes($t, $links)
        );

        return $this->escaper->escape($text);
    }

    /**
     * Формирует HTML-сообщение со списком источников на основе
     * дедуплицированных ссылок. Возвращает null, если показывать нечего.
     *
     * @param  DataCollection<int, mixed>  $citations
     * @param  array<int, string>  $links
     */
    public function formatCitationsMessage(DataCollection $citations, array $links): ?string
    {
        return $this->citationsFormatter->format($citations, $links);
    }

    public function convertToHtml(string $text): string
    {
        $allowed = '<b><strong><i><em><u><s><del><code><pre><a><span><tg-spoiler>';
        // валидный для XML символ из Private Use Area — libxml его не выбросит
        $lineBreakMarker = "\u{E000}";

        $html = (string) $this->converter->convert($text);

        $dom = new DOMDocument;
        libxml_use_internal_errors(true);
        $dom->loadHTML(
            '<?xml encoding="utf-8"?><div id="root">'.$html.'</div>',
            LIBXML_NOERROR | LIBXML_NOWARNING
        );
        libxml_clear_errors();

        $this->flattenLists($dom, $lineBreakMarker);

        $root = $dom->getElementById('root');
        $html = '';
        foreach ($root->childNodes as $child) {
            $html .= $dom->saveHTML($child);
        }

        $html = preg_replace('/<\/p>\s*/', "\n\n", $html);
        $html = str_replace('<p>', '', $html);
        $html = strip_tags($html, $allowed);
        $html = str_replace($lineBreakMarker, "\n", $html);
        $html = preg_replace('/\n{3,}/', "\n\n", $html);

        return trim($html);
    }

    private function flattenLists(DOMDocument $dom, string $lineBreakMarker): void
    {
        $xpath = new DOMXPath($dom);

        while (true) {
            $lists = $xpath->query('//ul[not(.//ul) and not(.//ol)] | //ol[not(.//ul) and not(.//ol)]');
            if ($lists->length === 0) {
                break;
            }
            foreach ($lists as $list) {
                $this->replaceListWithMarkers($dom, $list, $lineBreakMarker);
            }
        }
    }

    private function replaceListWithMarkers(DOMDocument $dom, DOMElement $list, string $lineBreakMarker): void
    {
        $isOrdered = strtolower($list->nodeName) === 'ol';
        $fragment = $dom->createDocumentFragment();
        $index = 1;

        foreach (iterator_to_array($list->childNodes) as $li) {
            if (! ($li instanceof DOMElement)) {
                continue;
            }
            if (strtolower($li->nodeName) !== 'li') {
                continue;
            }
            foreach (iterator_to_array($li->childNodes) as $child) {
                if ($child instanceof DOMElement && strtolower($child->nodeName) === 'p') {
                    while ($child->firstChild) {
                        $li->insertBefore($child->firstChild, $child);
                    }
                    $li->insertBefore($dom->createTextNode($lineBreakMarker), $child);
                    $li->removeChild($child);
                }
            }

            $marker = $isOrdered ? ($index++.'. ') : '◦ ';
            $fragment->appendChild($dom->createTextNode($marker));

            while ($li->firstChild) {
                $node = $li->firstChild;
                $li->removeChild($node);

                // Выкидываем только "технический" whitespace pretty-print (содержит \n) —
                // например, отступ CommonMark между <p> и вложенным <ul>.
                // Обычный одиночный пробел " " между инлайн-элементами (например,
                // между </strong> и следующей ссылкой) — значимый, его сохраняем.
                if ($node instanceof DOMText && preg_match('/^[ \t]*\n[ \t]*$/u', $node->wholeText)) {
                    continue;
                }

                $fragment->appendChild($node);
            }

            $fragment->appendChild($dom->createTextNode($lineBreakMarker));
        }

        $list->parentNode->replaceChild($fragment, $list);
    }
}
