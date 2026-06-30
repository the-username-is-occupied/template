<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\ChatMessage;
use App\Models\Notebook;
use App\Services\TelegramBot\DatabaseMenu;
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
        $this->sessionService = $sessionService ?? new TelegramSessionService();
    }

    /**
     * Register all handlers on the bot instance
     */
    public function registerHandlers(): void
    {
        try {
            // 1. Сработает ТОЛЬКО если после /start идет пробел и ХОТЯ БЫ ОДИН символ параметра (\s+.+)
            $this->bot->onCommand('start\s+(?<base>.+)', function (Nutgram $bot, string $base) {
                Log::info('Сработал start С параметром: ' . $base);
                $this->handleStartCommand($bot, $base);
            });

            // 2. Сработает, если ввели чистый /start без параметров.
            $this->bot->onCommand('start', function (Nutgram $bot) {
                Log::info('Сработал start БЕЗ параметра');
                $this->handleStartCommand($bot, null);
            })->description('Начать');

            $this->bot->onCommand('db', function (Nutgram $bot) {
                Log::info('Команда /db дошла, запускаем InlineMenu');
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
        $this->sessionService->handleStartCommand($tgUserId, $base);

        $message = 'Hello! Your session has been updated';
        if ($base) {
            $message .= "\nActive base: " . $base;
        }

        $bot->sendMessage($message);
    }

    /**
     * Handle suggested question callback
     */
    public function handleSuggested(Nutgram $bot, string $q): void
    {
        try {
            $bot->answerCallbackQuery();

            [$messageId, $indexStr] = explode(':', $q);
            $number = (int) $indexStr;

            $msg = ChatMessage::find($messageId);
            if (!$msg) {
                Log::warning("ChatMessage not found for ID: {$messageId}");
                return;
            }

            $txt = $msg->askDto()->suggested[$number - 1]->question;
            
            $bot->sendMessage(
                text: $this->prepareTelegramMarkdown('_' . $txt . '_'),
                parse_mode: 'MarkdownV2'
            );

            $this->handleTextMessage($bot, $txt);
        } catch (Throwable $e) {
            Log::error($e->getMessage());
            throw $e;
        }
    }

    /**
     * Handle text messages
     */
    public function handleTextMessage(Nutgram $bot, ?string $q = null): void
    {
        try {
            $question = $q ?? $bot->message()->text;
            
            // Используется фиксированный UUID записной книжки согласно текущей конфигурации приложения
            $notebook = Notebook::find('019f0e89-a41b-7357-a61c-d8d82a655cdb');
            $msg = app()->make(AskService::class)->ask($notebook, $question);
            // $msg = ChatMessage::find('019f15b4-dd66-7132-a44a-aa476c7a1a83');
            
            $resolved = $msg->askDto()->resolve();
            $myLinks = $resolved->getCitationLinks();
            $text = $resolved->answer;
            $questions = $resolved->getQuestions();

            // Форматируем предложенные вопросы курсивом
            $formattedQuestions = array_map(fn($item) => "_{$item}_", $questions);
            $questionsText = "\n\n" . implode("\n", $formattedQuestions);

            $buttons = array_map(function ($index) use ($msg) {
                return InlineKeyboardButton::make((string) $index, callback_data: 'ask:' . $msg->id . ':' . $index);
            }, range(1, 3));

            $keyboard = InlineKeyboardMarkup::make()->addRow(...$buttons);

            $signature = "\n\n _Ответы AI могут быть неточны. Обязательно проверяйте их._";
            $message = $this->prepareTelegramMarkdown($text . $signature, $myLinks);
            $messagesToSend = $this->splitTelegramMarkdown($message);

            foreach ($messagesToSend as $messageChunk) {
                $bot->sendMessage(
                    text: $messageChunk,
                    parse_mode: 'MarkdownV2'
                );
            }

            $bot->sendMessage(
                text: $this->prepareTelegramMarkdown($questionsText),
                reply_markup: $keyboard,
                parse_mode: 'MarkdownV2'
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

    /**
     * Prepare text formatting specifically for Telegram MarkdownV2
     */
    public function prepareTelegramMarkdown(string $text, array $links = []): string
    {
        // Схлопываем дубликаты URL в массиве сносок и строим карту соответствия старых ключей к новым уникальным
        $uniqueUrls = [];
        $keyMap = [];
        foreach ($links as $key => $url) {
            if (!isset($uniqueUrls[$url])) {
                $uniqueUrls[$url] = $key;
            }
            $keyMap[$key] = $uniqueUrls[$url];
        }

        // 1. Очищаем лишние отступы слева в каждой строке
        $lines = explode("\n", $text);
        $lines = array_map(fn($line) => ltrim($line, " \t"), $lines);
        $text = implode("\n", $lines);

        // 2. Превращаем заголовки "### Название" в жирный текст
        $text = preg_replace_callback('/###\s*(.+)/u', function ($matches) {
            return '**' . $matches[1] . "**\n";
        }, $text);

        // 3. Разбираем квадратные скобки со сносками (поддерживает [24, 28-30] и дедуплицирует одинаковые ссылки)
        $text = preg_replace_callback('/\[([0-9\s,\-]+)\]/u', function ($matches) use ($keyMap, $links) {
            $rawInside = $matches[1];
            $parts = array_map('trim', explode(',', $rawInside));
            $numbers = [];

            foreach ($parts as $part) {
                if (str_contains($part, '-')) {
                    [$start, $end] = array_map('intval', explode('-', $part));
                    if ($start <= $end) {
                        $numbers = array_merge($numbers, range($start, $end));
                    }
                } else {
                    $numbers[] = (int) $part;
                }
            }

            // Маппим каждый номер сноски на его первородный уникальный ID ссылки
            $mappedNumbers = array_map(fn($num) => $keyMap[$num] ?? $num, $numbers);
            // Удаляем повторения внутри одной группы квадратных скобок (например, преобразуем [1, 1] в [1])
            $mappedNumbers = array_unique($mappedNumbers);

            $replacement = [];
            foreach ($mappedNumbers as $num) {
                if (isset($links[$num])) {
                    $replacement[] = "[{$num}]({$links[$num]})";
                } else {
                    $replacement[] = "[{$num}]";
                }
            }

            return implode(', ', $replacement);
        }, $text);

        // 4. Заменяем одиночные звездочки списков на аккуратный буллит "◦ "
        $text = preg_replace('/^\s*\*\s+/um', '◦ ', $text);

        // 5. Разрезаем текст для безопасного экранирования
        $pattern = '/(```.*?```|`.*?`|\*\*.*?\*\*|__.*?__|_.*?_|~.*?~|\|\|.*?\|\||\[[^\]]+\]\([^)]+\))/us';
        $parts = preg_split($pattern, $text, -1, PREG_SPLIT_DELIM_CAPTURE);

        // Стандартная карта экранирования Telegram MarkdownV2
        $charsToEscape = ['_', '*', '[', ']', '(', ')', '~', '`', '>', '#', '+', '-', '=', '|', '{', '}', '.', '!'];
        $escapedMapping = [];
        foreach ($charsToEscape as $char) {
            $escapedMapping[$char] = '\\' . $char;
        }

        $codeEscapedMapping = ['`' => '\`', '\\' => '\\\\'];
        $urlEscapedMapping = [')' => '\)', '\\' => '\\\\'];

        foreach ($parts as &$part) {
            if (!preg_match($pattern, $part)) {
                $part = strtr($part, $escapedMapping);
                continue;
            }

            if (str_starts_with($part, '```') && str_ends_with($part, '```')) {
                $innerContent = substr($part, 3, -3);
                $part = '```' . strtr($innerContent, $codeEscapedMapping) . '```';

            } elseif (str_starts_with($part, '`') && str_ends_with($part, '`')) {
                $innerContent = substr($part, 1, -1);
                $part = '`' . strtr($innerContent, $codeEscapedMapping) . '`';

            } elseif (str_starts_with($part, '**') && str_ends_with($part, '**')) {
                $innerContent = substr($part, 2, -2);
                $part = '*' . strtr($innerContent, $escapedMapping) . '*';

            } elseif (str_starts_with($part, '__') && str_ends_with($part, '__')) {
                $innerContent = substr($part, 2, -2);
                $part = '__' . strtr($innerContent, $escapedMapping) . '__';

            } elseif (str_starts_with($part, '_') && str_ends_with($part, '_')) {
                $innerContent = substr($part, 1, -1);
                $part = '_' . strtr($innerContent, $escapedMapping) . '_';

            } elseif (str_starts_with($part, '~') && str_ends_with($part, '~')) {
                $innerContent = substr($part, 1, -1);
                $part = '~' . strtr($innerContent, $escapedMapping) . '~';

            } elseif (str_starts_with($part, '||') && str_ends_with($part, '||')) {
                $innerContent = substr($part, 2, -2);
                $part = '||' . strtr($innerContent, $escapedMapping) . '||';

            } elseif (str_starts_with($part, '[') && str_ends_with($part, ')')) {
                if (preg_match('/^\[(.*)\]\((.*)\)$/us', $part, $linkMatches)) {
                    $linkText = strtr($linkMatches[1], $escapedMapping);
                    $linkUrl = strtr($linkMatches[2], $urlEscapedMapping);
                    $part = '[' . $linkText . '](' . $linkUrl . ')';
                }
            }
        }
        unset($part);

        return implode('', $parts);
    }

    /**
     * Splits long markdown messages safely avoiding breaking markdown tags
     */
    public function splitTelegramMarkdown(string $text, int $maxLength = 4090): array
    {
        $prefix = "_\(к предыдущему сообщению\)_\n";

        $pattern = '/(```|__|\*|_|~|\|\||\[[^\]]+\]\([^)]+\)|\\\\.|.)/us';
        preg_match_all($pattern, $text, $matches);
        $tokens = $matches[0];

        $chunks = [];
        $chunkTokens = [];
        $stack = [];              
        $stackAtStart = [];       

        $lastNewlineIndexInChunk = -1;
        $lastNewlineStack = [];

        $isSubsequent = false;
        $i = 0;
        $totalTokens = count($tokens);

        while ($i < $totalTokens) {
            $token = $tokens[$i];

            $currentLength = $isSubsequent ? mb_strlen($prefix) : 0;
            foreach ($stackAtStart as $t) {
                $currentLength += mb_strlen($t);
            }
            foreach ($chunkTokens as $ct) {
                $currentLength += mb_strlen($ct);
            }

            $tempStack = $stack;
            if (in_array($token, ['```', '__', '||', '*', '_', '~'])) {
                if (!empty($tempStack) && end($tempStack) === $token) {
                    array_pop($tempStack);
                } else {
                    $tempStack[] = $token;
                }
            }

            $closingLen = 0;
            foreach ($tempStack as $t) {
                $closingLen += mb_strlen($t);
            }

            if ($currentLength + mb_strlen($token) + $closingLen > $maxLength) {
                if (empty($chunkTokens)) {
                    $chunkTokens[] = $token;
                    if (in_array($token, ['```', '__', '||', '*', '_', '~'])) {
                        if (!empty($stack) && end($stack) === $token) {
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

                if ($lastNewlineIndexInChunk !== -1) {
                    $savedTokens = array_slice($chunkTokens, 0, $lastNewlineIndexInChunk + 1);

                    $chunkText = ($isSubsequent ? $prefix : '');
                    if (!empty($stackAtStart)) {
                        $chunkText .= implode('', $stackAtStart);
                    }
                    $chunkText .= implode('', $savedTokens);
                    if (!empty($lastNewlineStack)) {
                        $chunkText .= implode('', array_reverse($lastNewlineStack));
                    }
                    $chunks[] = $chunkText;

                    $discardedCount = count($chunkTokens) - ($lastNewlineIndexInChunk + 1);
                    $i = $i - $discardedCount;

                    $chunkTokens = [];
                    $stack = $lastNewlineStack;
                    $stackAtStart = $stack;
                    $lastNewlineIndexInChunk = -1;
                    $lastNewlineStack = [];
                    $isSubsequent = true;

                    continue;
                } else {
                    $chunkText = ($isSubsequent ? $prefix : '');
                    if (!empty($stackAtStart)) {
                        $chunkText .= implode('', $stackAtStart);
                    }
                    $chunkText .= implode('', $chunkTokens);
                    if (!empty($stack)) {
                        $chunkText .= implode('', array_reverse($stack));
                    }
                    $chunks[] = $chunkText;

                    $chunkTokens = [];
                    $stackAtStart = $stack;
                    $isSubsequent = true;

                    continue;
                }
            }

            $chunkTokens[] = $token;
            if (in_array($token, ['```', '__', '||', '*', '_', '~'])) {
                if (!empty($stack) && end($stack) === $token) {
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

        if (!empty($chunkTokens)) {
            $chunkText = ($isSubsequent ? $prefix : '');
            if (!empty($stackAtStart)) {
                $chunkText .= implode('', $stackAtStart);
            }
            $chunkText .= implode('', $chunkTokens);
            if (!empty($stack)) {
                $chunkText .= implode('', array_reverse($stack));
            }
            $chunks[] = $chunkText;
        }

        return $chunks;
    }
}