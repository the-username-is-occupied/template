<?php

declare(strict_types=1);

namespace App\Services\TelegramBot;

use App\Models\ChatMessage;
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
        $this->formatter = $formatter ?? new TelegramMessageFormatterService;
    }

    public function handle(int $user_id, string $question, ?int $placeholderId = null)
    {

        try {

            $notebook = $this->sessionService->getActiveBase($user_id);
            if (! $notebook) {

                $this->bot->sendMessage(
                    text: 'База знаний не выбрана',
                    chat_id: $user_id
                );

                return;
            }

            $msg = app()->make(NLMAskService::class)->ask($notebook, $question);
            // $msg = ChatMessage::find("019f2893-1ad3-728b-b5f0-2dd9aae6f05d");

            $resolved = $msg->askDto()->resolve();
            $myLinks = $resolved->getCitationLinks();
            $text = $resolved->answer;
            $questions = $resolved->getQuestions();

            // Используем форматтер для подготовки сообщения
            $completeMessage = $this->formatter->prepareCompleteMessage($text, $myLinks);
            $messagesToSend = $this->formatter->splitLongMessage($completeMessage);

            foreach ($messagesToSend as $messageChunk) {
                $this->bot->sendMessage(
                    text: $messageChunk,
                    parse_mode: 'MarkdownV2',
                    chat_id: $user_id
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
                    chat_id: $user_id,
                    message_id: $placeholderId
                );
            }

            $this->bot->sendMessage(
                text: $questionsText,
                reply_markup: $keyboard,
                parse_mode: 'MarkdownV2',
                chat_id: $user_id
            );

        } catch (Throwable $e) {
            Log::error($e->getMessage());
            throw $e;
        }

    }
}
