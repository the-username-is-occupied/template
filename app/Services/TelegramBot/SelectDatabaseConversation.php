<?php

declare(strict_types=1);

namespace App\Services\TelegramBot;

use Illuminate\Support\Facades\Log;
use SergiX44\Nutgram\Conversations\Conversation;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;
use Throwable;

class SelectDatabaseConversation extends Conversation
{
    // Метод, который запускается первым
    public function start(Nutgram $bot)
    {
        try {
            Log::info('Команда дошла до start');
            // 1. Получаем список баз знаний (замените на вашу логику/сервис)
            // Пример структуры: [['id' => 1, 'name' => 'Маркетинг'], ['id' => 2, 'name' => 'Продажи']]
            $databases = $this->getDatabasesList();

            if (empty($databases)) {
                $bot->sendMessage('У вас пока нет созданных баз знаний.');
                $this->end();

                return;
            }

            // 2. Строим inline-клавиатуру
            $keyboard = InlineKeyboardMarkup::make();

            foreach ($databases as $db) {
                $keyboard->addRow(
                    // В callback_data передаем ID базы данных
                    InlineKeyboardButton::make($db['name'], callback_data: "select_db:{$db['id']}")
                );
            }

            // 3. Отправляем сообщение с меню
            $bot->sendMessage(
                text: 'Выберите базу знаний из списка:',
                reply_markup: $keyboard
            );

            // 4. Переводим разговор в следующий шаг ожидания
            $this->next('handleSelection');
        } catch (Throwable $e) {
            Log::error($e->getMessage());
            throw $e;
        }
    }

    // Шаг обработки клика
    public function handleSelection(Nutgram $bot)
    {
        // Проверяем, что пришел именно callback_query и он соответствует нашему паттерну
        if ($bot->callbackQuery() && preg_match('/^select_db:(\d+)$/', $bot->callbackQuery()->data, $matches)) {
            $dbId = $matches[1];

            // Вызываем вашу готовую функцию активации базы
            // Например: $this->activateDatabase($dbId);
            $this->activateDatabaseForUser($bot->userId(), $dbId);

            // Уведомляем пользователя всплывающим окном (toast)
            $bot->answerCallbackQuery(
                text: 'База знаний успешно активирована!',
                show_alert: false);

            // Изменяем текст исходного сообщения, чтобы зафиксировать выбор (и убрать кнопки)
            $bot->editMessageText("Активная база знаний успешно изменена на ID: {$dbId}");

            // Завершаем разговор
            $this->end();
        } else {
            // Если пользователь вместо клика по кнопке прислал текст или что-то еще
            $bot->sendMessage('Пожалуйста, выберите базу знаний, нажав на одну из кнопок меню.');
        }
    }

    // Имитация ваших существующих методов (замените на реальные)
    private function getDatabasesList(): array
    {
        // Здесь ваш код получения баз, например: return Database::all()->toArray();
        return [
            ['id' => 12, 'name' => 'База по Laravel'],
            ['id' => 15, 'name' => 'База по AI & RAG'],
        ];
    }

    private function activateDatabaseForUser($userId, $dbId): void
    {
        // Здесь ваша существующая функция активации
    }
}
