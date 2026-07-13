<?php

declare(strict_types=1);

namespace App\Services\TelegramBot;

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
            // $msg = ChatMessage::find('019f53fa-4cf2-70de-819b-47b291312502');

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

            if ($placeholderId) {
                $this->bot->deleteMessage(
                    chat_id: $tg_user_id,
                    message_id: $placeholderId
                );
            }

            $this->bot->sendMessage(
                text: $questionsText,
                reply_markup: $keyboard,
                parse_mode: 'MarkdownV2',
                chat_id: $tg_user_id
            );

        } catch (Throwable $e) {
            Log::error($e->getMessage());
            throw $e;
        }

    }
}
