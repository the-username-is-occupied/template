<?php

declare(strict_types=1);

namespace App\Services\TelegramBot;

use App\Models\ContentSource;
use App\Models\Notebook;
use App\Services\TelegramSessionService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Log;
use SergiX44\Nutgram\Conversations\InlineMenu;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;

class DatabaseMenu extends InlineMenu
{
    // Сколько баз знаний показываем на одной странице
    private const PER_PAGE = 10;

    // Этот метод отрисовывает стартовое меню (и все последующие страницы)
    public function start(Nutgram $bot)
    {
        Log::info('DatabaseMenu: Зашли в метод start. Начинаем сборку меню.');

        try {
            $page = $this->extractPage($bot);

            $this->renderPage($bot, $page);
        } catch (\Throwable $e) {
            Log::error('DatabaseMenu КРИТИЧЕСКАЯ ОШИБКА: '.$e->getMessage(), [
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }

    // Отрисовка конкретной страницы списка баз
    private function renderPage(Nutgram $bot, int $page): void
    {
        Log::info('DatabaseMenu: рендерим страницу '.$page);

        // Сбрасываем кнопки, оставшиеся от предыдущего рендера этого меню
        $this->clearButtons();

        $this->menuText('Выберите базу знаний из списка:');

        $databases = $this->getDatabasesList($page);

        foreach ($databases as $db) {
            // В callback_data передаем: "значение@имяМетодаКласса"
            // Nutgram сам распарсит это, вызовет метод 'selectDatabase'
            // и сохранит "db_id:XX" внутри callbackQuery
            $this->addButtonRow(
                InlineKeyboardButton::make(
                    text: $db->title.' ('.$db->slug.')',
                    callback_data: "db_id:{$db->id}@selectDatabase"
                )
            );
        }

        // Строка навигации: ◀️ Пред | номер страницы | След ▶️
        if ($databases->hasPages()) {
            $navRow = [];

            if ($databases->previousPageUrl()) {
                $navRow[] = InlineKeyboardButton::make(
                    text: '◀️ Пред',
                    callback_data: 'page:'.($databases->currentPage() - 1).'@start'
                );
            }

            $navRow[] = InlineKeyboardButton::make(
                text: $databases->currentPage().'/'.$databases->lastPage(),
                callback_data: 'noop@noop'
            );

            if ($databases->nextPageUrl()) {
                $navRow[] = InlineKeyboardButton::make(
                    text: 'След ▶️',
                    callback_data: 'page:'.($databases->currentPage() + 1).'@start'
                );
            }

            $this->addButtonRow(...$navRow);
        }

        $this->showMenu();
    }

    // Извлекаем номер страницы из callback_data (если это навигация), иначе страница 1
    private function extractPage(Nutgram $bot): int
    {
        $callbackData = $bot->callbackQuery()?->data;

        if ($callbackData !== null && preg_match('/^page:(\d+)$/', $callbackData, $matches)) {
            return (int) $matches[1];
        }

        return 1;
    }

    // Заглушка для кнопки-индикатора страницы, просто гасим "часики" в Telegram
    public function noop(Nutgram $bot)
    {
        $bot->answerCallbackQuery();
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

        $formatter = app()->make(TelegramMessageFormatterService::class);

        $notebook = Notebook::query()
            ->with(['contentSources' => fn ($i) => $i->withBundledItemsCount()])
            ->find($notebook->id);

        $sources = $notebook->contentSources
            ->map(fn (ContentSource $i) => sprintf('[%s](%s) - %s', $i->type->label(), $i->url, pluralize($i->original_items_count, ['источник', 'источника', 'источников'])))->join("\n");

        $msg = sprintf("%s\n\n%s\n\n%s",
            $notebook->title,
            $notebook->description->summary,
            $sources
        );
        $bot->sendMessage(text: $formatter->formatAnswer($msg), parse_mode: 'MarkdownV2');

        // Send suggested topics from notebook description if available
        $topics = $notebook->description?->suggested_topics ?? [];
        if ($topics !== []) {
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
    private function getDatabasesList(int $page = 1): LengthAwarePaginator
    {
        return Notebook::query()
            ->hasSlug()
            ->select(['id', 'title', 'slug'])
            ->orderBy('created_at')
            ->paginate(
                perPage: self::PER_PAGE,
                page: $page
            );
    }

    private function activateDatabaseForUser($userId, $dbId): void
    {
        app()->make(TelegramSessionService::class)->setActiveBase($userId, (string) $dbId);
    }
}
