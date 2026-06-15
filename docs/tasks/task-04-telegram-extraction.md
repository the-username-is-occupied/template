# Task 04 — Telegram Channel Extraction

## Контекст

Реализуем полный TG-флоу: запуск парсинга через TG Scraper, приём webhook-пачек с постами,
сохранение `original_items`, обнаружение и классификация ссылок, SSE-обновления в реальном времени.

Изучи перед началом: `docs/source-pipeline.md` (разделы "TG Channel Flow", "Linked Content Extraction Flow", "Восстановление после сбоя"), `docs/api/tg-scraper.md`.

Убедись, что `Task 01`, `Task 02`, `Task 03` выполнены.

---

## Что нужно сделать

### 1. TgScraperClient (расширение)

В `Task 02` был создан `TgScraperClient::getChannelMeta()`. Добавь методы:
- `scrape(string $contentSourceId, string $channel, string $hookUrl, array $config = []): void` — POST `/scrape`. Параметры конфига (`limit`, `from_id`, `to_id`, `from_date`, `to_date`) берутся из `config` и передаются только если заданы.
- `getStatus(): ScraperStatusData` — GET `/status`

Все DTO через `spatie/laravel-data`.

### 2. TelegramExtractor

Создай `App\Services\Extractors\TelegramExtractor` реализующий `SourceExtractorInterface`.

Логика `extract(ContentSource $source)`:
1. Устанавливает `content_source.extraction_status = uploading`
2. Формирует `hook_url` — внутренний URL Laravel-приложения для приёма webhook (`/api/webhooks/telegram-scraper`)
3. Вызывает `TgScraperClient::scrape()` с параметрами из `source.metadata.scrape_config`
4. Возвращает управление (парсинг асинхронный — результаты придут через webhook)

> Важно: Job не "ждёт" окончания парсинга. `ShouldBeUnique` лок на `content_source_id` удерживается пока job жив — это нормально, т.к. job завершается сразу после запуска парсинга. Завершение отслеживается через webhook `action=done`.

### 3. TelegramWebhookController

Создай `App\Http\Controllers\Api\TelegramWebhookController` с методом `handle(Request $request)`.

Маршрут: `POST /api/webhooks/telegram-scraper` (без auth middleware, доступен только из внутренней сети).

Логика обработки:
- `action=upload`: получает `content_source_id` и массив постов, диспатчит `ProcessTelegramChunkJob`
- `action=done`: диспатчит `TelegramScrapingDoneJob`

Webhook-контроллер должен быть максимально тонким — только валидация и dispatch.

### 4. ProcessTelegramChunkJob

Создай `App\Jobs\ProcessTelegramChunkJob`.

Логика:
1. Находит `ContentSource` по `content_source_id`
2. Для каждого поста из пачки создаёт `OriginalItem`:
   - `title` = первые N слов текста или ID поста
   - `full_text` = `post.text`
   - `source_url` = `post.url`
   - `published_at` = `post.date`
   - `word_count` = подсчёт слов через `preg_match_all('/[\p{L}\p{N}]+/u', $text)`
   - `metadata` = `{ views, type, reactions }`
3. Обновляет `content_source.last_fetched_id` до максимального ID в пачке
4. Передаёт ссылки из каждого поста в `LinkProcessorService::processLinks()`
5. Публикует SSE `parsing_progress` и `links_batch` (если есть новые ссылки)

### 5. TelegramScrapingDoneJob

Создай `App\Jobs\TelegramScrapingDoneJob`.

Логика:
1. Находит `ContentSource` и связанный `SourceDraft`
2. Устанавливает `content_source.extraction_status = extracted`
3. Устанавливает `draft.status = awaiting_index`
4. Публикует SSE `parsing_done` с `total_posts` и `total_links`

### 6. LinkProcessorService

Создай `App\Services\LinkProcessorService` с методом `processLinks(ContentSource $parentSource, OriginalItem $parentItem, array $links): void`.

Логика для каждой ссылки из массива `links`:
1. **Фильтрация вложенных каналов:** ссылки на TG-каналы без ID поста (t.me/channel без числа) — игнорировать
2. **Дедупликация:** проверить `content_sources.url` — если уже существует, пропустить
3. Создать `ContentSource`:
   - `user_id` = из `parentSource`
   - `url` = ссылка
   - `type` = определить через `SmartUrlDetector`
   - `discovery_method = auto_extracted`
   - `review_status = pending_review`
   - `parent_source_id` = `parentSource.id`
   - `parent_item_id` = `parentItem.id`
   - `extraction_status = pending`

Метод должен быть идемпотентным (повторный вызов с теми же ссылками не создаёт дубликатов).

### 7. API: Просмотр обнаруженных ссылок

Добавь endpoint для получения сгруппированных ссылок черновика:

`GET /api/source-drafts/{draft}/links`

Возвращает `pending_review` источники, сгруппированные по типу/домену/каналу. Для YouTube-ссылок — группировка по каналу через `YouTubeService::resolveChannelUrls()` (батчевый запрос). Для Telegram-постов — группировка по исходному каналу.

Структура ответа описана в разделе "Linked Content Extraction Flow" → "Агрегация и группировка" в `docs/source-pipeline.md`.

Каждый item включает `source_post_url` (через `parent_item_id → original_item.source_url`).

### 8. Восстановление после сбоя

Создай `App\Console\Commands\RetryStaleUploadsCommand` (или добавь в scheduler):

Каждые 30 минут находит `content_sources` с `extraction_status = uploading` и `updated_at < now() - 30 minutes`. Для каждого сбрасывает статус в `pending` и диспатчит `ProcessSourceJob`.

Добавь задачу в `routes/console.php`.

### 9. SSE Events

Добавь Events + Listeners для:
- `TelegramParsingProgress` → публикует `parsing_progress` (`draft_id`, `posts_parsed`, `links_discovered`)
- `TelegramLinksDiscovered` → публикует `links_batch` (`draft_id`, `links[]`)
- `TelegramParsingDone` → публикует `parsing_done` (`draft_id`, `total_posts`, `total_links`)

---

## Критерии готовности

- Подтверждение TG черновика → `TelegramExtractor` вызывает `TgScraperClient::scrape()` → webhook URL корректный
- `POST /api/webhooks/telegram-scraper` с `action=upload` → посты сохраняются в `original_items`, ссылки в `content_sources (pending_review)`
- `POST /api/webhooks/telegram-scraper` с `action=done` → `extraction_status = extracted`, `draft.status = awaiting_index`
- `LinkProcessorService` не создаёт дубликаты, фильтрует ссылки на TG-каналы без поста
- `GET /api/source-drafts/{draft}/links` возвращает корректно сгруппированные ссылки
- Stale upload recovery: `content_source` старше 30 минут в статусе `uploading` → retry
- Все внешние вызовы (TgScraperClient, YouTubeService, MercurePublisher) мокируются в тестах
