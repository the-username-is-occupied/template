# Task 04 — Telegram Channel Extraction

## Контекст

Реализуем полный TG-флоу: запуск парсинга через TG Scraper, приём webhook-пачек с постами,
сохранение `original_items`, обнаружение и классификация ссылок, SSE-обновления в реальном времени.

Изучи перед началом: `docs/source-pipeline.md` (разделы "TG Channel Flow", "Linked Content Extraction Flow", "Восстановление после сбоя"), `docs/api/tg-scraper.md`.

Убедись, что `Task 01`, `Task 02`, `Task 03` выполнены.

---

## Что нужно сделать

### 1. TelegramExtractor (использует TGScraperService)

Создай `App\Services\Extractors\TelegramExtractor` реализующий `SourceExtractorInterface`.

Логика `extract(ContentSource $source)`:
1. Устанавливает `content_source.extraction_status = uploading`
2. Формирует `hook_url` — внутренний URL Laravel-приложения для приёма webhook (`/api/webhooks/telegram-scraper`)
3. Вызывает `TGScraperService::scrape()` с параметрами из `source.metadata.scrape_config` (используй существующий сервис из `App\Domain\Telegram\TGScraperService`, модифицировать его нельзя)
4. Возвращает управление (парсинг асинхронный — результаты придут через webhook)

> Важно: Job не "ждёт" окончания парсинга. `ShouldBeUnique` лок на `content_source_id` удерживается пока job жив — это нормально, т.к. job завершается сразу после запуска парсинга. Завершение отслеживается через webhook `action=done`.

### 2. TelegramWebhookController

Создай `App\Http\Controllers\Api\TelegramWebhookController` с методом `handle(Request $request)`.

Маршрут: `POST /api/webhooks/telegram-scraper` (без auth middleware, доступен только из внутренней сети).

Логика обработки:
- `action=upload`: 
  - Увеличивает счётчик `pending_chunks` в Redis через `TelegramChunkService::incrementPendingChunks()`
  - Диспатчит `ProcessTelegramChunkJob`
- `action=done`: 
  - Устанавливает флаг `scraping_done` в Redis через `TelegramChunkService::setScrapingDone()`
  - Проверяет `pending_chunks` в Redis
  - Если `pending_chunks == 0` — выполняет `TelegramChunkService::executeDoneLogic()` немедленно
  - Если `pending_chunks > 0` — ждёт завершения всех чанков (проверка в `finalizeChunk()`)

Webhook-контроллер должен быть максимально тонким — только валидация и вызовы сервиса.

### 3. ProcessTelegramChunkJob

Создай `App\Jobs\ProcessTelegramChunkJob`.

Логика:
1. Находит `ContentSource` по `content_source_id`
2. Вызывает `TelegramChunkService::processChunk()` для обработки постов:
   - Для каждого поста создаёт `OriginalItem`
   - `title` = первые 8 слов текста или "Post #ID"
   - `full_text` = `post.text`
   - `source_url` = `post.url`
   - `published_at` = `post.date`
   - `word_count` = подсчёт слов через `preg_match_all('/[\p{L}\p{N}]+/u', $text)`
   - `metadata` = `{ views, type, reactions }`
3. Вызывает `TelegramChunkService::finalizeChunk()` для завершения:
   - Декрементирует счётчик `pending_chunks` в Redis
   - Проверяет, все ли чанки обработаны и получен ли флаг `scraping_done`
   - Если да — выполняет логику завершения (обновляет статусы, диспатчит SSE)
4. Передаёт ссылки из каждого поста в `LinkProcessorService::processLinks()`
5. Публикует SSE `parsing_progress` и `links_batch` (если есть новые ссылки)

**Примечание:** Job больше не уникален (`ShouldBeUnique` удалён), не обновляет `last_fetched_id`.

### 4. TelegramChunkService (заменяет TelegramScrapingDoneJob)

Создай `App\Services\TelegramChunkService` — основной сервис для обработки чанков.

Методы:
- `processChunk(ContentSource $source, array $posts): array` — обработка пачки постов
- `finalizeChunk(ContentSource $source): void` — завершение чанка (декрементирует счётчик, проверяет готовность)
- `executeDoneLogic(ContentSource $source): void` — логика завершения (обновляет статусы, диспатчит SSE)
- `incrementPendingChunks(string $contentSourceId): void` — увеличивает счётчик в Redis
- `decrementPendingChunks(string $contentSourceId): int` — уменьшает счётчик в Redis
- `setScrapingDone(string $contentSourceId): void` — устанавливает флаг завершения в Redis
- `isScrapingDone(string $contentSourceId): bool` — проверяет флаг завершения
- `getPendingChunksCount(string $contentSourceId): int` — получает количество ожидающих чанков
- `cleanupRedisKeys(string $contentSourceId): void` — очищает Redis-ключи после завершения

Redis-ключи:
- `telegram:source:{content_source_id}:pending_chunks` — счётчик ожидающих чанков
- `telegram:source:{content_source_id}:scraping_done` — флаг завершения парсинга

**Примечание:** `TelegramScrapingDoneJob` удалён. Его логика перенесена в `executeDoneLogic()`.

### 5. LinkProcessorService

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

### 6. API: Просмотр обнаруженных ссылок

Добавь endpoint для получения сгруппированных ссылок черновика:

`GET /api/source-drafts/{draft}/links`

Возвращает `pending_review` источники, сгруппированные по типу/домену/каналу. Для YouTube-ссылок — группировка по каналу через `YouTubeService::resolveChannelUrls()` (батчевый запрос). Для Telegram-постов — группировка по исходному каналу.

Структура ответа описана в разделе "Linked Content Extraction Flow" → "Агрегация и группировка" в `docs/source-pipeline.md`.

Каждый item включает `source_post_url` (через `parent_item_id → original_item.source_url`).

### 7. Восстановление после сбоя

Создай `App\Console\Commands\RetryStaleUploadsCommand` (или добавь в scheduler):

Каждые 30 минут находит `content_sources` с `extraction_status = uploading` и `updated_at < now() - 30 minutes`. Для каждого сбрасывает статус в `pending` и диспатчит `ProcessSourceJob`.

Добавь задачу в `routes/console.php`.

### 8. SSE Events

Добавь Events + Listeners для:
- `TelegramParsingProgress` → публикует `parsing_progress` (`draft_id`, `posts_parsed`, `links_discovered`)
- `TelegramLinksDiscovered` → публикует `links_batch` (`draft_id`, `links[]`)
- `TelegramParsingDone` → публикует `parsing_done` (`draft_id`, `total_posts`, `total_links`)

---

## Критерии готовности

- Подтверждение TG черновика → `TelegramExtractor` вызывает `TGScraperService::scrape()` → webhook URL корректный
- `POST /api/webhooks/telegram-scraper` с `action=upload` → посты сохраняются в `original_items`, ссылки в `content_sources (pending_review)`
- `POST /api/webhooks/telegram-scraper` с `action=done` → `extraction_status = extracted`, `draft.status = awaiting_index`
- `LinkProcessorService` не создаёт дубликаты, фильтрует ссылки на TG-каналы без поста
- `GET /api/source-drafts/{draft}/links` возвращает корректно сгруппированные ссылки
- Stale upload recovery: `content_source` старше 30 минут в статусе `uploading` → retry
- Все внешние вызовы (TGScraperService, YouTubeService, MercurePublisher) мокируются в тестах
