# Task 05 — YouTube Channel Extraction

## Контекст

Реализуем YT-флоу: загрузка списка видео через `YouTubeService`, батчевое извлечение транскриптов
через временные технические ноутбуки NLM, distributed locking, crash recovery.

Изучи перед началом: `docs/source-pipeline.md` (раздел "YouTube Flow"), `docs/account-pool.md` (раздел "Tech Notebooks Management"), `docs/data-model.md` (таблица `tech_notebooks`).

Убедись, что `Task 01`, `Task 02`, `Task 03` выполнены.

> ⚠️ Перед началом проверь, существует ли уже `AccountService` и логика управления `tech_notebooks` в кодовой базе (это часть Account Pool). Если да — переиспользуй. Если нет — реализуй только минимально необходимое для этой задачи (описано ниже).

---

## Что нужно сделать

### 1. YouTubeExtractor

Создай `App\Services\Extractors\YouTubeExtractor` реализующий `SourceExtractorInterface`.

Логика `extract(ContentSource $source)`:

1. Получает список video URL из `source.metadata` (они уже сохранены на этапе `awaiting_index`)
2. Устанавливает `extraction_status = extracting`
3. Запускает цикл батчевого извлечения:
   ```
   while (необработанные URL остались):
       а. Запрашивает у AccountService свободный тех. ноутбук типа source_extractor
          с максимальным количеством свободных слотов
       б. Вычисляет размер пачки = min(доступных слотов в ноутбуке, оставшихся URL)
       в. Захватывает distributed lock через AccountService::acquireTechNotebookLock()
       г. В блоке try/finally:
          — Загружает пачку URL как YouTube-источники в тех. ноутбук через NlmClient
          — Ждёт индексации (поллинг статуса)
          — Извлекает транскрипты (ask или export)
          — НЕМЕДЛЕННО удаляет загруженные источники из ноутбука (в finally)
          — Сохраняет транскрипты как OriginalItems
       д. Освобождает lock
   ```
4. После обработки всех URL: `extraction_status = extracted`, SSE `extraction_done`

**Важно:** удаление источников из NLM должно быть в `finally` — даже при ошибке.

### 2. Минимальный AccountService (если не существует)

Если `AccountService` ещё не реализован в проекте, создай `App\Services\AccountService` с минимально необходимыми методами:

- `getAvailableTechNotebook(string $type): ?TechNotebook` — возвращает ноутбук с наибольшим числом свободных слотов (`max_sources - sources_count`), статус `idle` или `busy`. Возвращает `null` если все `full` или `degraded`.
- `acquireTechNotebookLock(TechNotebook $notebook, string $lockKey): bool` — устанавливает `locked_at = now()`, `locked_by = lockKey`, меняет статус на `busy`. Атомарно через DB transaction + pessimistic lock.
- `releaseTechNotebookLock(TechNotebook $notebook): void` — очищает `locked_at`, `locked_by`, меняет статус обратно на `idle` (или `full` если слоты закончились).
- `incrementSourcesCount(TechNotebook $notebook, int $count): void`
- `decrementSourcesCount(TechNotebook $notebook, int $count): void`

Distributed lock реализуй через DB-level pessimistic lock (`lockForUpdate()`) внутри транзакции, а не через Redis — надёжнее при работе с несколькими Job workers.

### 3. NlmClient (интеграция)

Проверь, существует ли `NlmClient` или аналог в кодовой базе. Если нет — создай `App\Http\Clients\NlmClient` с методами для работы с тех. ноутбуком:

- `addYoutubeSource(string $notebookId, string $videoUrl): string` — добавляет YouTube URL как источник, возвращает `source_id` в NLM
- `deleteSource(string $notebookId, string $sourceId): void`
- `waitForIndexing(string $notebookId, string $sourceId, int $timeoutSeconds = 120): void` — поллит статус индексации
- `getTranscript(string $notebookId, string $sourceId): string` — извлекает транскрипт через ask или export

Базовый URL берётся из конфига. Конкретный API NLM (FastAPI сервис) должен уже существовать — ориентируйся на то, что есть в проекте.

### 4. Визард: загрузка списка видео (YT-специфичный шаг)

В `SourceDraftService` добавь метод `loadVideoList(SourceDraft $draft, array $contentTypes): void`:
1. Вызывает `YouTubeService::getVideoUrls(channelId, $contentTypes)`
2. Сохраняет список video URL в черновике (в `channel_meta` или отдельном поле)
3. Меняет `draft.status = awaiting_index`
4. Публикует SSE `videos_loaded`

Этот метод вызывается из `SourceService::confirmAndProcess()` для YouTube-источников.

### 5. API Endpoint: подтверждение YT

Расширь `POST /api/source-drafts/{draft}/confirm` для YT:

Принимает `content_types` (массив: `['video', 'shorts', 'streams']`, по умолчанию `['video', 'shorts']`).

Для YouTube: запускает `FetchYoutubeVideoListJob` (тонкий диспетчер), который вызывает `SourceDraftService::loadVideoList()`. Это async — сразу возвращает `202`.

Добавь также поддержку "снятия галочек" при индексации: `POST /api/source-drafts/{draft}/index` принимает `video_urls` — финальный список URL для извлечения. Этот список сохраняется в `content_source.metadata` перед dispatch `ProcessSourceJob`.

### 6. SSE Events

Добавь Events + Listeners для:
- `YoutubeVideosLoaded` → публикует `videos_loaded` (`draft_id`, `videos[]` с id, url, title, duration, thumbnail)

---

## Crash Recovery

`CleanupStaleTechNotebooksJob` (реализуй или убедись что существует):

Каждые 15 минут находит `tech_notebooks` со статусом `busy` и `locked_at < now() - 15 minutes`.
Для каждого:
1. Пытается вызвать `deleteAllSources` через NlmClient
2. Если успешно — `status = idle`, `sources_count = 0`, `locked_at = null`
3. Если ошибка — `status = degraded`

Добавь в scheduler (`routes/console.php`).

---

## Критерии готовности

- Подтверждение YT черновика → загрузка видео → SSE `videos_loaded` → пользователь нажимает «Индексировать» → `YouTubeExtractor` батчами извлекает транскрипты
- В finally блоке источники всегда удаляются из тех. ноутбука
- `AccountService::acquireTechNotebookLock()` атомарен: два параллельных вызова не захватят один ноутбук
- `CleanupStaleTechNotebooksJob` находит зависшие ноутбуки и очищает их
- Все вызовы NlmClient и AccountService мокируются в тестах
- Feature-тест: сценарий "YouTube channel extraction with 3 videos in 2 batches"
