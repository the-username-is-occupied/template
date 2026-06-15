# Task 03 — Core Extraction Infrastructure

## Контекст

Реализуем каркас для обработки источников после подтверждения пользователем:
`SourceService` → `ProcessSourceJob` → `ExtractorFactory` → конкретный экстрактор.

Также реализуем экстракторы для двух типов источников, которые Laravel может обработать самостоятельно: `website` (HTTP-скрейпинг) и `text` (простые текстовые файлы .txt / .md).

> ⚠️ **Важно про файловые форматы:** NLM принимает множество форматов (pdf, docx, pptx, csv, epub, аудио, видео, изображения и др.), но Laravel **не может** самостоятельно извлечь из них текст. Такие форматы обрабатываются через загрузку в технический NLM ноутбук — аналогично YouTube. Этот экстрактор (`NlmFileExtractor`) реализуется в **Task 05**. В данной задаче только закладываем интерфейс и маппинг.

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

| Тип источника | Экстрактор | Где реализован |
|---|---|---|
| `telegram_channel`, `telegram_post` | `TelegramExtractor` | Task 04 |
| `youtube_channel`, `youtube_video` | `YouTubeExtractor` | Task 05 |
| `text` | `TextExtractor` | Task 03 (эта задача) |
| `website` | `WebsiteExtractor` | Task 03 (эта задача) |
| `pdf`, `docx`, `csv`, `pptx`, `epub`, `audio`, `video`, изображения | `NlmFileExtractor` | Task 05 |

Фабрика должна уметь разрешать экстракторы из IoC-контейнера, чтобы тесты могли подменять их через `$this->mock()`.

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

Обрабатывает только тип `text` — файлы форматов `.txt` и `.md`, которые Laravel может прочитать напрямую.

Логика:
- Читает содержимое файла из Storage по `source.file_ref`
- Создаёт один `OriginalItem` с `full_text` = содержимое файла
- Подсчитывает `word_count`
- Устанавливает `extraction_status = extracted`, публикует SSE `extraction_done`

### 7. WebsiteExtractor

Создай `App\Services\Extractors\WebsiteExtractor` реализующий `SourceExtractorInterface`.

Обрабатывает тип `website` — произвольный HTTP URL.

Логика:
- HTTP GET страницы через Laravel HTTP Client
- Извлечение читаемого текста (strip_tags, или получение текста из `<main>` / `<article>` / `<body>`)
- Создаёт один `OriginalItem` с `title` из `<title>` тега, `full_text` = извлечённый текст, `source_url` = URL
- Устанавливает `extraction_status = extracted`, публикует SSE `extraction_done`

Фатальная ошибка (4xx, недоступный хост) — не retry, сразу `extraction_status = error`.

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
- `ExtractorFactory` корректно маппит все типы источников на экстракторы (для ещё не реализованных — выбрасывает `UnsupportedSourceTypeException`)
- Фатальная ошибка (например, недоступный URL) → `extraction_status = error`, SSE `error`
- Транзитная ошибка (таймаут) → исключение пробрасывается, Laravel делает retry
- `TextExtractor` unit-тест: txt-файл → один `OriginalItem` с корректным `word_count`
- `WebsiteExtractor` unit-тест: HTTP-ответ (мок) → один `OriginalItem` с title и текстом
- Feature-тесты: подтверждение черновика, обработка ошибок, dispatch job
