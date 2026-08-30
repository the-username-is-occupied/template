<?php

use App\Models\ChatMessage;
use App\Models\TgUser;
use App\Services\TelegramBot\AskService;
use App\Services\TelegramBot\TelegramMessageFormatterService;
use SergiX44\Nutgram\Nutgram;

function tt()
{
    app()->make(Nutgram::class);
    app()->make(TelegramMessageFormatterService::class);
    ChatMessage::find('01a05409-b361-7107-a6f8-9b087c377547');

    return app()->make(AskService::class)->handle(TgUser::find(1), 'Как защитить бренд при выходе на маркетплейс?');
}

function testit(): void
{
    $bot = app()->make(Nutgram::class);
    $formatter = app()->make(TelegramMessageFormatterService::class);
    $msg = ChatMessage::find('01a05409-b361-7107-a6f8-9b087c377547');
    $text = $msg->askDto()->answer;
    $resolved = $msg->askDto()->resolve();
    $myLinks = $resolved->getCitationLinks();
    $questions = $resolved->getQuestions();
    $completeMessage = $formatter->prepareCompleteMessage($text, $myLinks);

    foreach ($completeMessage as $html) {
        $bot->sendMessage(
            text: $html,
            chat_id: 598755356,
            parse_mode: 'HTML',
            disable_web_page_preview: true
        );
    }

    $questionsText = $formatter->formatSuggestedQuestions($questions);
    $keyboard = $formatter->createSuggestedQuestionsKeyboard($msg->id, $questions);

    $bot->sendMessage(
        text: $questionsText,
        chat_id: '598755356',
        parse_mode: 'MarkdownV2',
        reply_markup: $keyboard
    );
}

if (! function_exists('format_duration')) {
    function format_duration(float $duration): string
    {
        return $duration < 0.1 ? round($duration * 1000, 1).'ms' : round($duration, 2).'s';
    }
}

if (! function_exists('pluralize')) {
    /**
     * Russian pluralization helper.
     *
     * @param  int|numeric  $number  The number to pluralize for
     * @param  array  $forms  Array of three forms: [singular, few, many]
     *                        e.g., ['источник', 'источника', 'источников']
     * @return string The number with the correct form
     *
     * Usage: pluralize(1, ['источник', 'источника', 'источников']) => "1 источник"
     *        pluralize(5, ['источник', 'источника', 'источников']) => "5 источников"
     */
    function pluralize(int|string $number, array $forms): string
    {
        $num = (int) abs($number);
        $lastTwo = $num % 100;
        $lastOne = $num % 10;

        if ($lastTwo >= 11 && $lastTwo <= 19) {
            $form = 2; // many: источников
        } else {
            switch ($lastOne) {
                case 1:
                    $form = 0; // singular: источник
                    break;
                case 2:
                case 3:
                case 4:
                    $form = 1; // few: источника
                    break;
                default:
                    $form = 2; // many: источников
            }
        }

        return $number.' '.$forms[$form];
    }
}
