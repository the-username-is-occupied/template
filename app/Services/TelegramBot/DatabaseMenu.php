<?php

declare(strict_types=1);

namespace App\Services\TelegramBot;

use App\Models\ContentSource;
use App\Models\Notebook;
use App\Services\TelegramSessionService;
use Illuminate\Support\Facades\Log;
use SergiX44\Nutgram\Conversations\InlineMenu;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;

class DatabaseMenu extends InlineMenu
{
    // Этот метод отрисовывает стартовое меню
    public function start(Nutgram $bot)
    {
        Log::info('DatabaseMenu: Зашли в метод start. Начинаем сборку меню.');

        try {
            // Устанавливаем СТРОГО чистый текст без спецсимволов и эмодзи
            Log::info('DatabaseMenu: метод start вызван');

            $this->menuText('Выберите активную базу знаний из списка:');

            // Ваши базы данных
            $databases = $this->getDatabasesList();

            foreach ($databases as $db) {
                // В callback_data передаем: "значение@имяМетодаКласса"
                // Nutgram сам распарсит это, вызовет метод 'selectDatabase'
                // и сохранит "db_id:XX" внутри callbackQuery
                $this->addButtonRow(
                    InlineKeyboardButton::make(
                        text: $db['title'].' ('.$db['slug'].')',
                        callback_data: "db_id:{$db['id']}@selectDatabase"
                    )
                );
            }

            $this->showMenu();

        } catch (\Throwable $e) {
            Log::error('DatabaseMenu КРИТИЧЕСКАЯ ОШИБКА: '.$e->getMessage(), [
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }

    // Этот метод сработает, когда пользователь нажмет на кнопку с базой
    public function selectDatabase(Nutgram $bot)
    {
        Log::info('DatabaseMenu: метод selectDatabase вызван');

        // Получаем callback_data нажатой кнопки
        $callbackData = $bot->callbackQuery()->data;
        Log::info('DatabaseMenu: $callbackData = '.$callbackData);
        try {
            if (preg_match('/^db_id:(.+)$/', $callbackData, $matches)) {
                $dbId = $matches[1];

                $notebook = Notebook::find($dbId);

                if (! $notebook) {
                    $bot->sendMessage('Ошибка: база знаний не найдена.');

                }

                $this->activateDatabaseForUser($bot->userId(), $dbId);

                $bot->answerCallbackQuery();

                $this->end();

                static::sendApply($bot, $notebook);
            } else {
                $bot->sendMessage('Пожалуйста, выберите базу знаний');
            }
        } catch (\Throwable $e) {
            Log::error('DatabaseMenu КРИТИЧЕСКАЯ ОШИБКА: '.$e->getMessage(), [
                'trace' => $e->getTraceAsString(),
            ]);
        }

    }

    public static function sendApply(Nutgram $bot, Notebook $notebook): void
    {
        $notebook = Notebook::query()
            ->with(['contentSources' => fn ($i) => $i->withItemsCount()])
            ->find($notebook->id);

        $sources = $notebook->contentSources
            ->map(fn (ContentSource $i) => sprintf('%s: %s (%s)', $i->type->label(), $i->original_items_count, $i->url))->join("\n");

        $desc = $notebook->description ? $notebook->description->summary : '';
        $msg = sprintf("Активная база знаний успешно изменена\n\n%s\n\n%s\n\n%s", $notebook->title, $desc, $sources);
        $bot->sendMessage(text: $msg);

        // Send suggested topics from notebook description if available
        $topics = $notebook->description?->suggested_topics ?? [];
        if ($topics !== []) {
            $formatter = app()->make(TelegramMessageFormatterService::class);
            $questionsText = $formatter->formatDescriptionQuestions($topics);
            $keyboard = $formatter->createDescriptionQuestionsKeyboard($notebook->id, $topics);

            $bot->sendMessage(
                text: $questionsText,
                reply_markup: $keyboard,
                parse_mode: 'MarkdownV2',
            );
        }
    }

    // Твои методы
    private function getDatabasesList(): array
    {
        return Notebook::query()->hasSlug()->select(['id', 'title', 'slug'])->get()->toArray();
    }

    private function activateDatabaseForUser($userId, $dbId): void
    {
        app()->make(TelegramSessionService::class)->setActiveBase($userId, (string) $dbId);
    }
}
