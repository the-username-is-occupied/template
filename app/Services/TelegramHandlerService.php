<?php

declare(strict_types=1);

namespace App\Services;

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
        $this->bot->onCommand('start(?:\s+{base})?', function (Nutgram $bot, ?string $base) {
            $this->handleStartCommand($bot, $base);
        })->description('The start command!');

        $this->bot->onCallbackQueryData('ask:{q}', function (Nutgram $bot, string $q) {
            $this->handleSuggested($bot, $q);
        });

        $this->bot->onMessageType(MessageType::TEXT, function (Nutgram $bot) {
            $this->handleTextMessage($bot);
        });

        $this->bot->fallback(function (Nutgram $bot) {
            $this->handleFallback($bot);
        });
    }

    /**
     * Handle the /start command
     */
    private function handleStartCommand(Nutgram $bot, ?string $base): void
    {
        $tgUserId = $bot->userId();

        $session = $this->sessionService->handleStartCommand($tgUserId, $base);

        $message = 'Hello! Your session has been '.($session->wasRecentlyCreated ? 'created' : 'updated');
        if ($base) {
            $message .= "\nActive base: ".$base;
        }

        $bot->sendMessage($message);
    }

    public function handleSuggested(Nutgram $bot, string $q){
         

    
    try
    {

    
        $bot->answerCallbackQuery();
        $bot->sendMessage(
            text: '_Вопрос номер такой то_',
            parse_mode: 'MarkdownV2' // Используем Markdown для форматирования
        );

        $this->handleTextMessage($bot);
        }
        catch(Throwable $e){
            Log::error($e->getMessage());
            throw $e;
        }

    }

    /**
     * Handle text messages
     */
    private function handleTextMessage(Nutgram $bot, ?string $q = null): void
    {
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

        $text = 'Инвестируйте в **обучение работе с искусственным интеллектом**, так как через 5–7 лет без этих навыков человек может окончательно потерять конкурентоспособность [1]. При воспитании детей стоит делать упор на **многозадачность и умение решать разноплановые вопросы**, а не только на глубокое знание одной узкой дисциплины [2]. 
        
        В плане карьеры учитывайте, что **доход на 80% определяется отраслью и регионом**, а не только личными трудовыми усилиями, поэтому мобильность и готовность сменить сферу деятельности в 4 раза выгоднее, чем простое продвижение на одном месте [3]. Для долгосрочного успеха лучше выбирать **медицинские, инженерные или технические специальности (STEM)**, так как гуманитарное образование во всём мире становится избыточным и менее доходным [4, 5]. 
        
        Для личного благополучия **выбирайте спутника жизни, максимально похожего на вас** по уровню образования и политическим взглядам, так как это ключевые факторы устойчивого брака [6, 7]. Помните, что именно **брачный партнёр заслуживает больше всего внимания и заботы**, так как коллеги, друзья и даже дети с меньшей вероятностью будут рядом с вами в последней главе жизни [8]. 
        
        Чтобы справляться со стрессом, эксперты рекомендуют **заниматься спортом и употреблять больше ферментированных продуктов**, поддерживая здоровье кишечника как «второго мозга» [9]. Также мужчинам стоит **сократить время за видеоиграми**, чтобы избежать социального одиночества и задержки ментального взросления [10]. 
        
        Если вы стремитесь к приватности, **используйте старые «аналоговые» автомобили**, так как современные машины собирают и передают огромные массивы данных о разговорах и даже физиологическом состоянии водителя [11]. В финансовых вопросах **не держите деньги под подушкой**, а используйте депозиты или инвестиции в недвижимость, чтобы инфляция не обнулила ваши накопления [12, 13].';
        // $text = 'Текст с <a href="https://tg.dev">ссылкой</a>';

        $keyboard = InlineKeyboardMarkup::make();
        foreach (range(1, 3) as $i => $index) {
            $keyboard->addRow(InlineKeyboardButton::make((string) $index, callback_data: 'ask:'.$index));
        }

        $bot->sendMessage(
            text: $this->prepareTelegramMarkdown($text, $myLinks),
            reply_markup: $keyboard,
            parse_mode: 'MarkdownV2' // Используем Markdown для форматирования
        );
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
        // 1. Разбираем составные ссылки вида [4, 5] -> [4](url), [5](url)
        $text = preg_replace_callback('/\[([0-9\s,]+)\]/u', function ($matches) use ($links) {
            $numbers = array_map('trim', explode(',', $matches[1]));
            $replacement = [];

            foreach ($numbers as $num) {
                if (isset($links[$num])) {
                    // Сразу собираем в стандартный Markdown
                    $replacement[] = "[{$num}]({$links[$num]})";
                } else {
                    $replacement[] = "[{$num}]";
                }
            }

            return implode(', ', $replacement);
        }, $text);

        // 2. Шаг экранирования: разбиваем текст на части, чтобы НЕ экранировать ссылки и жирность
        // Регулярка находит: **жирный текст** ИЛИ [текст](ссылка)
        $pattern = '/(\*\*.*?\*\*|\[[^\]]+\]\([^)]+\))/u';
        $parts = preg_split($pattern, $text, -1, PREG_SPLIT_DELIM_CAPTURE);

        $charsToEscape = ['_', '*', '[', ']', '(', ')', '~', '`', '>', '#', '+', '-', '=', '|', '{', '}', '.', '!'];
        // Создаем массив пар для экранирования (например, '.' => '\.')
        $escapedMapping = [];
        foreach ($charsToEscape as $char) {
            $escapedMapping[$char] = '\\'.$char;
        }

        foreach ($parts as &$part) {
            // Если эта часть НЕ является ссылкой и НЕ является жирным текстом — экранируем её
            if (! preg_match($pattern, $part)) {
                $part = strtr($part, $escapedMapping);
            } else {
                // Если это жирный текст, переводим из стандартного **text** в ТГ-формат *text*
                if (str_starts_with($part, '**') && str_ends_with($part, '**')) {
                    $innerContent = substr($part, 2, -2);
                    // Экранируем спецсимволы ВНУТРИ жирного текста, но не саму звездочку
                    $innerContent = strtr($innerContent, $escapedMapping);
                    $part = '*'.$innerContent.'*';
                }
                // Если это ссылка [текст](url), её внутренности (url) экранировать не нужно, Telegram её съест так
            }
        }

        return implode('', $parts);
    }
}
