# Task 02 — Smart URL Detection & Source Draft Management

## Контекст

Реализуем точку входа Source Pipeline: визард добавления источника.
Пользователь вставляет URL → система определяет тип → загружает мета-данные → черновик ждёт подтверждения.

Изучи перед началом: `docs/source-pipeline.md` (разделы "Smart URL Detection", "Source Draft Lifecycle", "SSE Events"), `docs/sse.md`.


---

## Что нужно сделать

### 1. SmartUrlDetector

Создай сервис `App\Services\SmartUrlDetector` с методом `detect(string $url): SourceTypeData`.

Логика определения типа описана в таблице "Smart URL Detection" в `docs/source-pipeline.md`. Возвращаемый DTO должен содержать определённый тип (`ContentSourceType` enum) и нормализованный идентификатор (handle канала, video id и т.п.).

Обработка множественного ввода (несколько URL через пробел/перенос строки) — на уровне сервиса или контроллера, каждый URL обрабатывается независимо.

### 2. SourceDraftService

Создай `App\Services\SourceDraftService` — оркестратор всего жизненного цикла черновика.

Методы сервиса:
- `create(User $user, Notebook $notebook, string $rawInput): Collection` — парсит `rawInput`, создаёт один или несколько `SourceDraft` (по одному на URL), запускает фоновую загрузку мета-данных для каждого. Возвращает коллекцию созданных черновиков.
- `abandon(SourceDraft $draft): void` — переводит в статус `abandoned`
- `handleMetaLoaded(SourceDraft $draft, array $channelMeta): void` — сохраняет мета, меняет статус на `awaiting_confirm`, публикует SSE `meta_loaded`
- `handleMetaError(SourceDraft $draft, string $code, string $message): void` — меняет статус на `abandoned`, публикует SSE `error`

### 3. Загрузка мета-данных

Создай Job `FetchSourceMetaJob` (тонкий диспетчер), который:
- Для `telegram_channel` — вызывает TG Scraper API (`GET /channel/{channel}`) и получает `title`, `description`, `members`, `avatar_url`
- Для `youtube_channel` — вызывает `YouTubeService::getChannelInfo()`
- Для `youtube_video`, `website`, `pdf` — базовое определение title (HTTP HEAD / Open Graph / просто URL)
- После успеха вызывает `SourceDraftService::handleMetaLoaded()`
- При ошибке вызывает `SourceDraftService::handleMetaError()`

Для вызовов TG Scraper есть app/Domain/Telegram/TGScraperService.php.

### 4. API Endpoints

Создай контроллер `Api\SourceDraftController` с маршрутами (префикс `/api/source-drafts`):

| Метод | Путь | Действие |
|---|---|---|
| POST | `/` | Создать черновик(и) из `raw_input` + `knowledge_base_id` |
| GET | `/{draft}` | Получить текущее состояние черновика |
| DELETE | `/{draft}` | Перевести черновик в `abandoned` |

Все маршруты под auth middleware. Политика авторизации: пользователь может работать только со своими черновиками.

Ответ GET возвращает черновик вместе с `channel_meta` и `scrape_config`.

### 5. SSE Events

SSE публикуется через связку Event → Listener → `MercurePublisher` (согласно `docs/sse.md`).

Топик: `user.{userId}.source-drafts`

Создай Laravel Events + Listeners для:
- `SourceMetaLoaded` → публикует `meta_loaded` (содержит `draft_id`, `channel_meta`)
- `SourceDraftError` → публикует `error` (содержит `draft_id`, `code`, `message`)

Payload описан в разделе "SSE Events" в `docs/source-pipeline.md`.

### 6. DTOs

Все входные и выходные данные через `spatie/laravel-data`:
- `CreateSourceDraftData` (входной DTO для POST)
- `ChannelMetaData` (мета-данные канала)
- `SourceDraftResource` (выходной DTO для API-ответа)

---

## Критерии готовности

- POST `/api/source-drafts` с TG URL создаёт черновик, запускает `FetchSourceMetaJob`, который через `TGScraperService` (мок в тестах) получает мета и публикует SSE `meta_loaded`
- POST с несколькими URL создаёт несколько черновиков
- `SmartUrlDetector` корректно определяет все типы из таблицы в документации
- Feature-тесты покрывают: создание черновика, определение типа URL, обработку ошибки загрузки мета, abandon
- Listeners мокируются в тестах (`$this->mock(SendSourceMetaLoadedNotification::class)`)
