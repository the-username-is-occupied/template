<?php

declare(strict_types=1);

namespace App\Services\TelegramBot;

use App\Domain\NotebookLM\DTOs\SuggestedTopicDTO;
use App\Services\TelegramBot\Formatting\TelegramCitationsFormatter;
use App\Services\TelegramBot\Formatting\TelegramFootnoteLinker;
use App\Services\TelegramBot\Formatting\TelegramMarkdownEscaper;
use App\Services\TelegramBot\Formatting\TelegramMessageSplitter;
use App\Services\TelegramBot\Formatting\TelegramSignatureBuilder;
use App\Services\TelegramBot\Formatting\TelegramTextNormalizer;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;
use Spatie\LaravelData\DataCollection;

final class TelegramMessageFormatterService
{
    private const MAX_MESSAGE_LENGTH = 4090;

    private const CONTINUATION_PREFIX = "_\(к предыдущему сообщению\)_\n";

    public function __construct(
        private readonly TelegramTextNormalizer $normalizer,
        private readonly TelegramFootnoteLinker $footnoteLinker,
        private readonly TelegramMarkdownEscaper $escaper,
        private readonly TelegramMessageSplitter $splitter,
        private readonly TelegramSignatureBuilder $signatureBuilder,
        private readonly TelegramCitationsFormatter $citationsFormatter,
    ) {}

    /**
     * Форматирует ответ с сносками под Telegram MarkdownV2 (без подписи).
     *
     * @param  array<int, string>  $links
     */
    public function formatAnswer(string $text, array $links = []): string
    {
        return $this->prepareTelegramMarkdown($text, $links);
    }

    /**
     * Форматирует предложенные вопросы курсивом.
     *
     * @param  array<int, string>  $questions
     */
    public function formatSuggestedQuestions(array $questions): string
    {
        $formattedQuestions = array_map(static fn (string $item) => "_{$item}_", $questions);

        return $this->prepareTelegramMarkdown("\n\n".implode("\n", $formattedQuestions));
    }

    /**
     * Инлайн-клавиатура для предложенных вопросов из ChatMessage.
     *
     * @param  array<int, string>  $questions
     */
    public function createSuggestedQuestionsKeyboard(string $messageId, array $questions): InlineKeyboardMarkup
    {
        $buttons = array_map(
            static fn (int $index) => InlineKeyboardButton::make(
                (string) $index,
                callback_data: 'ask:'.$messageId.':'.$index
            ),
            range(1, min(3, count($questions)))
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
            static fn (int $index) => InlineKeyboardButton::make(
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

        return $this->prepareTelegramMarkdown("\n\n".implode("\n", $formatted));
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

        return array_map(
            static fn (string $chunk) => $chunk.$signature,
            $chunks
        );
    }

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
}
