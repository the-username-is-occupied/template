<?php

declare(strict_types=1);

namespace App\Services\TelegramBot;

use App\Data\PreprocessData;
use App\Exceptions\DailyLimitExceededException;
use App\Models\ChatMessage;
use App\Models\Notebook;
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
            if (! $notebook instanceof Notebook) {

                $this->bot->sendMessage(
                    text: 'База знаний не выбрана',
                    chat_id: $tg_user_id
                );

                return;
            }

            $msg = app()->make(NLMAskService::class)->ask($notebook, $question, $tg_user->user, $followUpMessage);

            // $msg = ChatMessage::query()->find('01a052c7-ac6b-711c-a2c9-dcf56e1022f7');

            if ($msg instanceof PreprocessData) {
                $this->removePlaceholder($tg_user_id, $placeholderId);

                $this->bot->sendMessage(
                    text: $msg->suggested_short_reply,
                    chat_id: $tg_user_id,
                    parse_mode: 'HTML',
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
                    chat_id: $tg_user_id,
                    parse_mode: 'HTML',
                    disable_web_page_preview: true
                );
            }

            $citationsMessage = $this->formatter->formatCitationsMessage($resolved->citations, $myLinks);

            if ($citationsMessage !== null) {
                $this->bot->sendMessage(
                    text: $citationsMessage,
                    chat_id: $tg_user_id,
                    parse_mode: 'HTML',
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
                chat_id: $tg_user_id,
                parse_mode: 'MarkdownV2',
                reply_markup: $keyboard
            );

        } catch (DailyLimitExceededException $e) {
            Log::error($e->getMessage());
            $this->removePlaceholder($tg_user_id, $placeholderId);

            $this->bot->sendMessage(
                text: '⚠️ '.$e->getMessage(),
                chat_id: $tg_user_id,
                parse_mode: 'HTML'
            );
            throw $e;
        } catch (Throwable $e) {
            Log::error($e->getMessage());
            throw $e;
        }

    }

    public function removePlaceholder(int|string $tg_user_id, $placeholderId): void
    {
        if ($placeholderId) {
            $this->bot->deleteMessage(
                chat_id: $tg_user_id,
                message_id: $placeholderId
            );
        }
    }
}
