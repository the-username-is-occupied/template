<?php

declare(strict_types=1);

namespace App\Services\TelegramBot\Formatting;

/**
 * Приводит "сырой" markdown от модели к виду, пригодному для дальнейшего
 * экранирования под Telegram MarkdownV2:
 *  - убирает случайные отступы;
 *  - "### Заголовок"      -> "**Заголовок**" -> итог: *Заголовок* (bold)
 *  - "* пункт списка"     -> "◦ пункт списка"
 *  - "**жирный**"         -> "*жирный*"        (bold в MarkdownV2)
 *  - "*курсив*"           -> "_курсив_"        (italic в MarkdownV2)
 *  - "~~зачёркнутый~~"    -> "~зачёркнутый~"
 *  - "||спойлер||"        -> остаётся как есть (уже валидный синтаксис Telegram)
 *  - код (`...` и ```...```) не трогается вообще — защищается плейсхолдерами
 *  - "[1, 2]" и подобные квадратные скобки не трогаются
 */
final class TelegramTextNormalizer
{
    private const CODE_PLACEHOLDER_FORMAT = "\x00CODE%d\x00";

    private const BOLD_PLACEHOLDER_FORMAT = "\x00BOLD%d\x00";

    /**
     * @param  (callable(string): string)|null  $footnoteLinker  Колбэк для линковки сносок
     *                                                           (например, TelegramFootnoteLinker::linkFootnotes),
     *                                                           выполняется до headersToBold/bulletsToDots и
     *                                                           ПОСЛЕ извлечения code span'ов, чтобы "[1, 2]"
     *                                                           внутри кода не превращались в ссылки, а сам
     *                                                           паттерн сносок не конфликтовал с bold/italic.
     */
    public function normalize(string $text, ?callable $footnoteLinker = null): string
    {
        $text = $this->trimLines($text);

        [$text, $codeSpans] = $this->extractCodeSpans($text);

        if ($footnoteLinker !== null) {
            $text = $footnoteLinker($text);
        }

        $text = $this->headersToBold($text);
        $text = $this->bulletsToDots($text);
        $text = $this->boldAndItalicToTelegram($text);
        $text = $this->strikethroughToTelegram($text);

        // Спойлер ||...|| уже валиден для MarkdownV2 — трогать не нужно,
        // и ни одно из правил выше не задевает символ "|".

        return $this->restoreCodeSpans($text, $codeSpans);
    }

    public function trimLines(string $text): string
    {
        $lines = array_map(
            static fn (string $line): string => ltrim($line, " \t"),
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
            '/^#{1,6}\s*(.+)$/um',
            static fn (array $matches): string => '**'.$matches[1].'**',
            $text
        );
    }

    /**
     * "* пункт списка" -> "◦ пункт списка"
     */
    public function bulletsToDots(string $text): string
    {
        return preg_replace('/^[ \t]*[*\-]\s+/um', '◦ ', $text);
    }

    /**
     * "~~текст~~" -> "~текст~" (Telegram MarkdownV2 strikethrough)
     */
    public function strikethroughToTelegram(string $text): string
    {
        return preg_replace('/~~(.+?)~~/us', '~$1~', $text);
    }

    /**
     * "**жирный**" -> "*жирный*"
     * "*курсив*"   -> "_курсив_"
     *
     * Порядок важен: сначала прячем double-star bold за плейсхолдеры,
     * потом всё оставшееся одиночное "*...*" — это курсив, конвертируем
     * его в подчёркивания, и только в конце возвращаем bold обратно
     * уже в виде одиночной звезды. Если делать это одним проходом или
     * в обратном порядке, bold после конвертации в "*...*" сам попадёт
     * под правило курсива и превратится в "_..._ " — что неверно.
     */
    private function boldAndItalicToTelegram(string $text): string
    {
        $boldChunks = [];

        $text = preg_replace_callback(
            '/\*\*(.+?)\*\*/us',
            static function (array $m) use (&$boldChunks): string {
                $key = sprintf(self::BOLD_PLACEHOLDER_FORMAT, count($boldChunks));
                $boldChunks[$key] = $m[1];

                return $key;
            },
            $text
        );

        $text = preg_replace_callback(
            '/\*(.+?)\*/us',
            static fn (array $m): string => '_'.$m[1].'_',
            $text
        );

        return strtr($text, array_map(
            static fn (string $content): string => '*'.$content.'*',
            $boldChunks
        ));
    }

    /**
     * Прячет ```code blocks``` и `inline code` за плейсхолдеры, чтобы
     * все остальные правила (bold/italic/списки/заголовки) их не касались.
     *
     * @return array{0: string, 1: array<string, string>}
     */
    private function extractCodeSpans(string $text): array
    {
        $spans = [];

        $extractor = static function (array $m) use (&$spans): string {
            $key = sprintf(self::CODE_PLACEHOLDER_FORMAT, count($spans));
            $spans[$key] = $m[0];

            return $key;
        };

        // Сначала блоки кода (тройные кавычки), затем инлайн-код,
        // чтобы одиночный бэктик не порезал тройной раньше времени.
        $text = preg_replace_callback('/```.*?```/us', $extractor, $text);
        $text = preg_replace_callback('/`[^`\n]+`/u', $extractor, $text);

        return [$text, $spans];
    }

    private function restoreCodeSpans(string $text, array $spans): string
    {
        return strtr($text, $spans);
    }
}
