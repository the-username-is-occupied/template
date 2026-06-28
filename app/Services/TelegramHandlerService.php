<?php

declare(strict_types=1);

namespace App\Services;

use App\Services\TelegramBot\DatabaseMenu;
use Database\Factories\AskResultDTOFactory;
use Illuminate\Support\Facades\Log;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\MessageType;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;
use Throwable;

class TelegramHandlerService
{
    private Nutgram $bot;

    private TelegramSessionService $sessionService;

    public function __construct(?Nutgram $bot = null, ?TelegramSessionService $sessionService = null)
    {
        $this->bot = $bot ?? app(Nutgram::class);
        $this->sessionService = $sessionService ?? new TelegramSessionService;
    }

    /**
     * Register all handlers on the bot instance
     */
    public function registerHandlers(): void
    {
        try {
            // 1. Сработает ТОЛЬКО если после /start идет пробел и ХОТЯ БЫ ОДИН символ параметра (\s+.+)
            $this->bot->onCommand('start\s+(?<base>.+)', function (Nutgram $bot, string $base) {
                Log::info('Сработал start С параметром: '.$base);
                $this->handleStartCommand($bot, $base);
            });

            // 2. Сработает, если ввели чистый /start без параметров.
            // Именно этот роут уйдет в меню Telegram.
            $this->bot->onCommand('start', function (Nutgram $bot) {
                Log::info('Сработал start БЕЗ параметра');
                $this->handleStartCommand($bot, null);
            })->description('Начать');
            
            $this->bot->onCommand('db', function (Nutgram $bot) {
                Log::info('Команда /db дошла, запускаем InlineMenu');

                // Запуск InlineMenu происходит через метод-хелпер меню
                DatabaseMenu::begin($bot);
            })->description('Выбрать активную базу знаний');

            $this->bot->onCallbackQueryData('ask:{q}', function (Nutgram $bot, string $q) {
                $this->handleSuggested($bot, $q);
            });

            $this->bot->onMessageType(MessageType::TEXT, function (Nutgram $bot) {
                $this->handleTextMessage($bot);
            });

            $this->bot->fallback(function (Nutgram $bot) {
                $this->handleFallback($bot);
            });

            
        } catch (Throwable $e) {
            Log::error($e->getMessage());
            throw $e;
        }
    }

    /**
     * Handle the /start command
     */
    private function handleStartCommand(Nutgram $bot, ?string $base): void
    {
        $tgUserId = $bot->userId();

        $session = $this->sessionService->handleStartCommand($tgUserId, $base);

        $message = 'Hello! Your session has been updated';
        if ($base) {
            $message .= "\nActive base: ".$base;
        }

        $bot->sendMessage($message);
    }

    public function handleSuggested(Nutgram $bot, string $q)
    {

        try {

            $bot->answerCallbackQuery();
            $bot->sendMessage(
                text: '_Вопрос номер такой то_',
                parse_mode: 'MarkdownV2' // Используем Markdown для форматирования
            );

            $this->handleTextMessage($bot);
        } catch (Throwable $e) {
            Log::error($e->getMessage());
            throw $e;
        }

    }

    /**
     * Handle text messages
     */
    private function handleTextMessage(Nutgram $bot, ?string $q = null): void
    {
        try {
            $myLinks = [
                1 => 'https://example.com/source1',
                2 => 'https://example.com/source2',
                3 => 'https://example.com/source3',
                4 => 'https://example.com/source4',
                5 => 'https://example.com/source5',
                6 => 'https://example.com/source5',
                7 => 'https://example.com/source5',
                8 => 'https://example.com/source5',
                9 => 'https://example.com/source5',
                10 => 'https://example.com/source5',
                11 => 'https://example.com/source5',
                12 => 'https://example.com/source12',
                13 => 'https://example.com/source13',
            ];

            $questions = [
                'Защита от манипуляций — это навык?',
                'Каковы типичные риторические уловки?',
                'Что нужно знать чтобы не стать жертвой пропаганды?',
            ];

            $text = AskResultDTOFactory::test()->answer;

            // Add questions separated by ---
            $formattedQuestions = [];
            foreach ($questions as $index => $question) {
                $questionNumber = $index + 1;
                $formattedQuestions[] = "_{$questionNumber}. {$question}_";
            }
            $questionsText = "\n\n".implode("\n", $formattedQuestions);

            $buttons = array_map(function ($index) {
                return InlineKeyboardButton::make((string) $index, callback_data: 'ask:'.$index);
            }, range(1, 3));

            $keyboard = InlineKeyboardMarkup::make()->addRow(...$buttons);

            $signature = "\n\n _Сгенерировано в Eolithic._ \n _Ответы AI могут быть неточны. Обязательно проверяйте их._";
            $message = $this->prepareTelegramMarkdown($text.$signature, $myLinks);
            $messagesToSend = $this->splitTelegramMarkdown($message);

            foreach ($messagesToSend as $key => $message) {
                $bot->sendMessage(
                    text: $message,
                    parse_mode: 'MarkdownV2' // Используем Markdown для форматирования
                );
            }
            $bot->sendMessage(
                text: $this->prepareTelegramMarkdown($questionsText),
                reply_markup: $keyboard,
                parse_mode: 'MarkdownV2' // Используем Markdown для форматирования
            );

        } catch (Throwable $e) {
            Log::error($e->getMessage());
            throw $e;
        }
    }

    /**
     * Handle fallback for unrecognized commands/messages
     */
    private function handleFallback(Nutgram $bot): void
    {

        $bot->sendMessage('Извините, Я не понял');
    }

    public function prepareTelegramMarkdown(string $text, array $links = []): string
    {
        // 1. Очищаем лишние отступы слева в каждой строке, чтобы текст не уезжал вправо
        $lines = explode("\n", $text);
        $lines = array_map(function ($line) {
            return ltrim($line, " \t"); // удаляем только пробелы и табуляцию слева
        }, $lines);
        $text = implode("\n", $lines);

        // 2. Превращаем заголовки "### Название" в жирный текст и добавляем пустую строку снизу
        $text = preg_replace_callback('/###\s*(.+)/u', function ($matches) {
            return '**'.$matches[1]."**\n"; // Обратите внимание на двойные кавычки для \n
        }, $text);

        // 3. Разбираем квадратные скобки (и перечисления [1, 2], и диапазоны [10-12])
        $text = preg_replace_callback('/\[([0-9\s,\-]+)\]/u', function ($matches) use ($links) {
            $rawInside = $matches[1];
            $numbers = [];

            if (str_contains($rawInside, '-')) {
                [$start, $end] = array_map('intval', explode('-', $rawInside));
                if ($start <= $end) {
                    $numbers = range($start, $end);
                }
            } else {
                $numbers = array_map('trim', explode(',', $rawInside));
            }

            $replacement = [];
            foreach ($numbers as $num) {
                if (isset($links[$num])) {
                    $replacement[] = "[{$num}]({$links[$num]})";
                } else {
                    $replacement[] = "[{$num}]";
                }
            }

            return implode(', ', $replacement);
        }, $text);

        // 4. Заменяем одиночные звездочки списков на аккуратный буллит "•"
        $text = preg_replace('/^\s*\*\s+/um', '◦ ', $text);

        // 5. Разрезаем текст для безопасного экранирования.
        // Добавлен флаг /s, чтобы точки в .*? хватали и переносы строк (актуально для ``` блоков кода)
        $pattern = '/(```.*?```|`.*?`|\*\*.*?\*\*|__.*?__|_.*?_|~.*?~|\|\|.*?\|\||\[[^\]]+\]\([^)]+\))/us';
        $parts = preg_split($pattern, $text, -1, PREG_SPLIT_DELIM_CAPTURE);

        // Стандартная карта экранирования Telegram MarkdownV2
        $charsToEscape = ['_', '*', '[', ']', '(', ')', '~', '`', '>', '#', '+', '-', '=', '|', '{', '}', '.', '!'];
        $escapedMapping = [];
        foreach ($charsToEscape as $char) {
            $escapedMapping[$char] = '\\'.$char;
        }

        // Специфические правила экранирования Telegram для кода и ссылок
        $codeEscapedMapping = ['`' => '\`', '\\' => '\\\\'];
        $urlEscapedMapping = [')' => '\)', '\\' => '\\\\'];

        foreach ($parts as &$part) {
            // Если это обычный текст, экранируем его полностью
            if (! preg_match($pattern, $part)) {
                $part = strtr($part, $escapedMapping);

                continue;
            }

            // Обработка различных элементов форматирования
            if (str_starts_with($part, '```') && str_ends_with($part, '```')) {
                // Блок кода: экранируются только ` и \
                $innerContent = substr($part, 3, -3);
                $part = '```'.strtr($innerContent, $codeEscapedMapping).'```';

            } elseif (str_starts_with($part, '`') && str_ends_with($part, '`')) {
                // Встроенный код: экранируются только ` и \
                $innerContent = substr($part, 1, -1);
                $part = '`'.strtr($innerContent, $codeEscapedMapping).'`';

            } elseif (str_starts_with($part, '**') && str_ends_with($part, '**')) {
                // Жирный текст: стандартный Markdown (**) преобразуем в MarkdownV2 (*)
                $innerContent = substr($part, 2, -2);
                $part = '*'.strtr($innerContent, $escapedMapping).'*';

            } elseif (str_starts_with($part, '__') && str_ends_with($part, '__')) {
                // Подчеркнутый текст (__text__)
                $innerContent = substr($part, 2, -2);
                $part = '__'.strtr($innerContent, $escapedMapping).'__';

            } elseif (str_starts_with($part, '_') && str_ends_with($part, '_')) {
                // Курсив (_text_)
                $innerContent = substr($part, 1, -1);
                $part = '_'.strtr($innerContent, $escapedMapping).'_';

            } elseif (str_starts_with($part, '~') && str_ends_with($part, '~')) {
                // Зачеркнутый текст (~text~)
                $innerContent = substr($part, 1, -1);
                $part = '~'.strtr($innerContent, $escapedMapping).'~';

            } elseif (str_starts_with($part, '||') && str_ends_with($part, '||')) {
                // Спойлер (||text||)
                $innerContent = substr($part, 2, -2);
                $part = '||'.strtr($innerContent, $escapedMapping).'||';

            } elseif (str_starts_with($part, '[') && str_ends_with($part, ')')) {
                // Ссылки вида [текст](url)
                if (preg_match('/^\[(.*)\]\((.*)\)$/us', $part, $linkMatches)) {
                    $linkText = strtr($linkMatches[1], $escapedMapping);
                    $linkUrl = strtr($linkMatches[2], $urlEscapedMapping); // В URL экранируем только ) и \
                    $part = '['.$linkText.']('.$linkUrl.')';
                }
            }
        }
        unset($part);

        return implode('', $parts);
    }

    public function splitTelegramMarkdown(string $text, int $maxLength = 4090): array
    {
        // Префикс для последующих сообщений (к предыдущему сообщению) курсивом
        // Скобки в MarkdownV2 должны быть экранированы
        $prefix = "_\(к предыдущему сообщению\)_\n";

        // Регулярное выражение для разбивки текста на атомарные токены разметки
        $pattern = '/(```|__|\*|_|~|\|\||\[[^\]]+\]\([^)]+\)|\\\\.|.)/us';
        preg_match_all($pattern, $text, $matches);
        $tokens = $matches[0];

        $chunks = [];
        $chunkTokens = [];
        $stack = [];              // Текущий стек открытых тегов
        $stackAtStart = [];       // Теги, которые уже были открыты на старте этого чанка

        $lastNewlineIndexInChunk = -1;
        $lastNewlineStack = [];

        $isSubsequent = false;
        $i = 0;
        $totalTokens = count($tokens);

        while ($i < $totalTokens) {
            $token = $tokens[$i];

            // Считаем длину текущего чанка с учетом префикса и открывающих тегов
            $currentLength = $isSubsequent ? mb_strlen($prefix) : 0;
            foreach ($stackAtStart as $t) {
                $currentLength += mb_strlen($t);
            }
            foreach ($chunkTokens as $ct) {
                $currentLength += mb_strlen($ct);
            }

            // Симулируем состояние стека для подсчета длины закрывающих тегов
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

            // Проверяем, не превысим ли мы лимит при добавлении токена
            if ($currentLength + mb_strlen($token) + $closingLen > $maxLength) {

                // Защита от бесконечного цикла: если чанк пустой, принудительно берем этот токен
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

                // Если в чанке был перенос строки, бьем по нему (чтобы сохранить красивые абзацы)
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

                    // Откатываем указатель на токены, которые не вошли в этот чанк
                    $discardedCount = count($chunkTokens) - ($lastNewlineIndexInChunk + 1);
                    $i = $i - $discardedCount;

                    // Сброс для следующего сообщения
                    $chunkTokens = [];
                    $stack = $lastNewlineStack;
                    $stackAtStart = $stack;
                    $lastNewlineIndexInChunk = -1;
                    $lastNewlineStack = [];
                    $isSubsequent = true;

                    continue;
                } else {
                    // Если переносов строк не было (цельный огромный кусок текста), бьем жестко
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

            // Если токен помещается, фиксируем его
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

        // Собираем остаток текста в финальный чанк
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
