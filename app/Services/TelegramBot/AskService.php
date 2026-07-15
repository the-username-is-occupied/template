<?php

declare(strict_types=1);

namespace App\Services\TelegramBot;

use App\Data\PreprocessData;
use App\Exceptions\DailyLimitExceededException;
use App\Models\ChatMessage;
use App\Models\TgUser;
use App\Services\AskService as NLMAskService;
use App\Services\TelegramSessionService;
use Illuminate\Support\Facades\Log;
use SergiX44\Nutgram\Nutgram;
use Throwable;

class AskService
{
    public function __construct(
        private Nutgram $bot,
        private ?TelegramSessionService $sessionService = null,
        private ?TelegramMessageFormatterService $formatter = null
    ) {
        $this->bot = $bot ?? app(Nutgram::class);
        $this->sessionService = $sessionService ?? new TelegramSessionService;
        $this->formatter = $formatter ?? app()->make(TelegramMessageFormatterService::class);
    }

    public function handle(TgUser $tg_user, string $question, ?int $placeholderId = null, ?ChatMessage $followUpMessage = null): void
    {

        try {

            $tg_user_id = $tg_user->id();
            $notebook = $this->sessionService->getActiveBase($tg_user_id);
            if (! $notebook) {

                $this->bot->sendMessage(
                    text: 'База знаний не выбрана',
                    chat_id: $tg_user_id
                );

                return;
            }

            $msg = app()->make(NLMAskService::class)->ask($notebook, $question, $tg_user->user, $followUpMessage);

            if ($msg instanceof PreprocessData) {
                $this->removePlaceholder($tg_user_id, $placeholderId);

                $this->bot->sendMessage(
                    text: $msg->suggested_short_reply,
                    parse_mode: 'HTML',
                    chat_id: $tg_user_id,
                    disable_web_page_preview: true
                );

                return;
            }

            $resolved = $msg->askDto()->resolve();
            $myLinks = $resolved->getCitationLinks();
            $text = $resolved->answer;
            $questions = $resolved->getQuestions();

            // Используем форматтер для подготовки сообщения
            $completeMessage = $this->formatter->prepareCompleteMessage($text, $myLinks);

            foreach ($completeMessage as $messageChunk) {
                $this->bot->sendMessage(
                    text: $messageChunk,
                    parse_mode: 'MarkdownV2',
                    chat_id: $tg_user_id,
                    disable_web_page_preview: true
                );
            }

            $citationsMessage = $this->formatter->formatCitationsMessage($resolved->citations, $myLinks);

            if ($citationsMessage !== null) {
                $this->bot->sendMessage(
                    text: $citationsMessage,
                    parse_mode: 'HTML',
                    chat_id: $tg_user_id,
                    disable_web_page_preview: true
                );
            }

            if (! $questions) {
                return;
            }
            // Форматируем предложенные вопросы и создаем клавиатуру
            $questionsText = $this->formatter->formatSuggestedQuestions($questions);
            $keyboard = $this->formatter->createSuggestedQuestionsKeyboard($msg->id, $questions);

            $this->removePlaceholder($tg_user_id, $placeholderId);

            $this->bot->sendMessage(
                text: $questionsText,
                reply_markup: $keyboard,
                parse_mode: 'MarkdownV2',
                chat_id: $tg_user_id
            );

        } catch (DailyLimitExceededException $e) {
            Log::error($e->getMessage());
            $this->removePlaceholder($tg_user_id, $placeholderId);

            $this->bot->sendMessage(
                text: '⚠️ '.$e->getMessage(),
                parse_mode: 'HTML',
                chat_id: $tg_user_id
            );
            throw $e;
        } catch (Throwable $e) {
            Log::error($e->getMessage());
            throw $e;
        }

    }

    public function removePlaceholder($tg_user_id, $placeholderId)
    {
        if ($placeholderId) {
            $this->bot->deleteMessage(
                chat_id: $tg_user_id,
                message_id: $placeholderId
            );
        }
    }
}
