<?php

declare(strict_types=1);

namespace App\Services;

use App\Jobs\TelegramAskJob;
use App\Models\ChatMessage;
use App\Models\Notebook;
use App\Services\TelegramBot\DatabaseMenu;
use App\Services\TelegramBot\TelegramMessageFormatterService;
use Illuminate\Support\Facades\Log;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\MessageType;
use Throwable;

class TelegramHandlerService
{
    private Nutgram $bot;

    private TelegramSessionService $sessionService;

    private TelegramMessageFormatterService $formatter;

    public function __construct(
        ?Nutgram $bot = null,
        ?TelegramSessionService $sessionService = null,
        ?TelegramMessageFormatterService $formatter = null
    ) {
        $this->bot = $bot ?? app(Nutgram::class);
        $this->sessionService = $sessionService ?? new TelegramSessionService;
        $this->formatter = $formatter ?? new TelegramMessageFormatterService;
    }

    /**
     * Register all handlers on the bot instance
     */
    public function registerHandlers(): void
    {
        try {
            // 1. Сработает ТОЛЬКО если после /start идет пробел и ХОТЯ БЫ ОДИН символ параметра (\s+.+)
            $this->bot->onCommand('start\s+(?<base>.+)', function (Nutgram $bot, string $base) {
                $this->handleStartCommand($bot, $base);
            });

            // 2. Сработает, если ввели чистый /start без параметров.
            $this->bot->onCommand('start', function (Nutgram $bot) {
                DatabaseMenu::begin($bot);
            })->description('Начать');

            $this->bot->onCommand('db', function (Nutgram $bot) {
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
        try {
            $tgUserId = $bot->userId();
            $notebook = Notebook::query()->slug($base)->first();

            if (! $notebook) {
                $bot->sendMessage('Не удалось найти базу знаний');
            }

            $this->sessionService->handleStartCommand($tgUserId, $notebook->id);
            DatabaseMenu::sendApply($bot, $notebook);
        } catch (Throwable $e) {
            Log::error($e->getMessage());
            throw $e;
        }
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
            if (! $msg) {
                Log::warning("ChatMessage not found for ID: {$messageId}");

                return;
            }

            $txt = $msg->askDto()->suggested[$number - 1]?->question ?? null;

            if (! $txt) {

                return;
            }

            $formattedText = $this->formatter->formatAnswer('_'.$txt.'_');

            $bot->sendMessage(
                text: $formattedText,
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
            $placeholderMessage = $bot->sendMessage(text: '*Генерирую ответ\\.\\.\\.*', parse_mode: 'MarkdownV2');
            $placeholderId = $placeholderMessage->message_id;
            TelegramAskJob::dispatch($bot->userId(), $q ?? $bot->message()->text, $placeholderId);
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
        // $bot->sendMessage('Извините, Я не понял');
    }
}
