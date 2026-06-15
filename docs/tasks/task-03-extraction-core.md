# Task 03 — Core Extraction Infrastructure

## Контекст

Реализуем каркас для обработки источников после подтверждения пользователем:
`SourceService` → `ProcessSourceJob` → `ExtractorFactory` → конкретный экстрактор.

Также реализуем простейший `TextExtractor` для website/pdf/text источников.

Изучи перед началом: `docs/source-pipeline.md` (раздел "Ключевые классы и интерфейсы", "Обработка ошибок"), `docs/architecture.md` (Thin Jobs Pattern).

Убедись, что `Task 01` и `Task 02` выполнены.

---

## Что нужно сделать

### 1. SourceService (Оркестратор)

Создай `App\Services\SourceService` — главный фасад для запуска обработки источника.

Методы:
- `confirmAndProcess(SourceDraft $draft, ?array $scrapeConfig = null): ContentSource` — вызывается при подтверждении пользователем. Обновляет `draft.scrape_config`, создаёт `ContentSource` (или находит существующий), диспатчит `ProcessSourceJob`. Для TG возвращает управление сразу (парсинг асинхронный). Для YT вызывается позже, на шаге `indexing`.
- `startIndexing(SourceDraft $draft, array $approvedUrls = []): void` — вызывается при нажатии «Индексировать» из `awaiting_index`. Обновляет `review_status` у ссылок, меняет `draft.status = indexing`, диспатчит `ProcessSourceJob` для основного источника.

Сервис не содержит бизнес-логики извлечения — только оркестрация и смена статусов.

### 2. ProcessSourceJob

Создай `App\Jobs\ProcessSourceJob`:
- Реализует `ShouldBeUnique` (уникальный по `content_source_id`)
- В методе `handle()` только: получить зависимости из контейнера, вызвать `SourceIndexingService::process($this->contentSourceId)`
- Не содержит никакой бизнес-логики

Настрой retry: 3 попытки с экспоненциальной задержкой. Транзитные ошибки (таймауты, 5xx) пробрасываются как исключения для автоматического retry. Фатальные ошибки перехватываются внутри `SourceIndexingService` и не пробрасываются.

### 3. SourceExtractorInterface

Создай `App\Contracts\SourceExtractorInterface`:
```
interface SourceExtractorInterface
{
    public function extract(ContentSource $source): void;
}
```

### 4. ExtractorFactory

Создай `App\Services\ExtractorFactory` с методом `make(ContentSource $source): SourceExtractorInterface`.

Маппинг типов на экстракторы:
- `telegram_channel`, `telegram_post` → `TelegramExtractor`
- `youtube_channel`, `youtube_video` → `YouTubeExtractor`
- `website`, `pdf`, `text`, `audio`, `video` → `TextExtractor`

### 5. SourceIndexingService

Создай `App\Services\SourceIndexingService` с методом `process(string $contentSourceId): void`.

Логика:
1. Загружает `ContentSource` из БД
2. Получает экстрактор через `ExtractorFactory`
3. Вызывает `$extractor->extract($source)`
4. Обрабатывает ошибки согласно матрице из `docs/source-pipeline.md` раздел "Обработка ошибок":
   - Фатальные (известные): пишет `extraction_status = error` + `error_message` + `error_code`, публикует SSE `error`, не пробрасывает
   - Транзитные (неизвестные): пробрасывает для retry

### 6. TextExtractor

Создай `App\Services\Extractors\TextExtractor` реализующий `SourceExtractorInterface`.

Упрощённая логика для MVP:
- `website`: HTTP GET страницы, извлечение текста (strip_tags или простой парсер), создание одного `OriginalItem`
- `pdf`, `audio`, `video`, `text`: создание `OriginalItem` из уже имеющихся данных (файл или текст уже есть в source)
- После успеха: `content_source.extraction_status = extracted`, публикует SSE `extraction_done`

Для HTTP-запросов к сайтам использовать Laravel HTTP Client (`Http::get()`).

### 7. API Endpoints

Добавь в `SourceDraftController`:

| Метод | Путь | Действие |
|---|---|---|
| POST | `/{draft}/confirm` | Подтвердить черновик, запустить обработку (TG: сразу; YT: загрузить список видео) |
| POST | `/{draft}/index` | Запустить индексацию (из состояния `awaiting_index`) |

Оба endpoint-а авторизованы, принадлежность черновика проверяется через Policy.

`POST /confirm` принимает необязательный `scrape_config` (для TG — фильтры парсинга).

`POST /index` принимает список одобренных/отклонённых URL для linked sources.

### 8. SSE Events

Добавь Event + Listener для:
- `ExtractionCompleted` → публикует SSE `extraction_done` (содержит `draft_id`, `content_source_id`, `items_count`)

---

## Критерии готовности

- `POST /api/source-drafts/{draft}/confirm` создаёт `ContentSource` и диспатчит `ProcessSourceJob`
- `ProcessSourceJob` делегирует в `SourceIndexingService`, который вызывает нужный экстрактор
- Фатальная ошибка (например, недоступный URL) → `extraction_status = error`, SSE `error`
- Транзитная ошибка (таймаут) → исключение пробрасывается, Laravel делает retry
- Feature-тесты: подтверждение черновика, обработка ошибок, dispatch job
- `TextExtractor` unit-тест: website URL → один `OriginalItem` в БД
