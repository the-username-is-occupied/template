<?php

declare(strict_types=1);

namespace App\Services\TelegramBot;

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
            $databases = [
                ['id' => 12, 'name' => '📚 База по Laravel'],
                ['id' => 15, 'name' => '🤖 База по AI & RAG'],
            ];

            foreach ($databases as $db) {
                // В callback_data передаем: "значение@имяМетодаКласса"
                // Nutgram сам распарсит это, вызовет метод 'selectDatabase'
                // и сохранит "db_id:XX" внутри callbackQuery
                $this->addButtonRow(
                    InlineKeyboardButton::make(
                        text: $db['name'],
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

        if (preg_match('/^db_id:(\d+)$/', $callbackData, $matches)) {
            $dbId = $matches[1];

            // Твоя готовая функция активации
            $this->activateDatabaseForUser($bot->userId(), $dbId);

            // Всплывающее уведомление в Telegram
            $bot->answerCallbackQuery(
                text: 'База знаний успешно активирована!',
                show_alert: false
            );

            // Обновляем сообщение, фиксируя выбор и убирая кнопки
            $this->menuText("Активная база знаний успешно изменена на ID: {$dbId}")
                ->clearButtons()
                ->showMenu();

            // Закрываем контекст меню
            $this->end();
        }
    }

    // Твои методы
    private function getDatabasesList(): array
    {
        // Замени на реальное получение данных из Laravel модели/сервиса
        return [
            ['id' => 12, 'name' => 'База по Laravel'],
            ['id' => 15, 'name' => 'База по AI & RAG'],
        ];
    }

    private function activateDatabaseForUser($userId, $dbId): void
    {
        // Твоя логика активации
    }
}
