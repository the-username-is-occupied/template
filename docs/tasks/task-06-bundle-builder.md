# Task 06 — Bundle Builder (LSM-style 4-Tier Hierarchy)

## Контекст

Реализуем систему упаковки `original_items` в MD-файлы для загрузки в NotebookLM.
Четырёхуровневая LSM-иерархия: `active_delta` → `active_quarter` → `frozen_half` → `frozen_full`.

Изучи перед началом: `docs/bundle-architecture.md` (полностью), `docs/source-pipeline.md` (разделы "MD Bundle Strategy", "Bundle File Format", "Citation Resolution").


---

## Что нужно сделать

### 1. BundleRenderer

Создай `App\Services\BundleRenderer` — отвечает только за рендеринг MD-файла из коллекции `OriginalItem`.

Формат файла описан в разделе "Bundle File Format" в `docs/source-pipeline.md`:
- Каждый item открывается строкой: `>` + 22 символа Base64URL UUID + пробел + JSON `{"title":"...","date":"..."}`
- Затем идёт `full_text` item-а
- Между items — пустая строка-разделитель

Метод `render(Collection $items): string` — возвращает итоговый MD-текст.

Вспомогательный метод `encodeItemId(string $uuid): string` — конвертирует UUID в 22-символьный Base64URL (убирает дефисы, декодирует hex → binary → base64url).

### 2. WordCounter

Создай `App\Services\WordCounter` с методом `count(string $text): int`.

Использует `preg_match_all('/[\p{L}\p{N}]+/u', $text, $matches)` для корректного подсчёта слов с кириллицей и латиницей.

### 3. BundleBuilder

Создай `App\Services\BundleBuilder` — ядро LSM-машины.

#### Константы порогов (можно вынести в конфиг):
- `ACTIVE_DELTA_MAX_WORDS` = 10 000
- `ACTIVE_QUARTER_MAX_WORDS` = 120 000

#### Основной метод `build(Notebook $notebook): void`:

1. Получает все `unbundled` `OriginalItem` (md_bundle_id IS NULL) для всех `ContentSource` этого ноутбука, отсортированные по `published_at ASC`
2. Если таких items нет — выходит
3. Определяет стратегию на основе суммарного `word_count`:
   - **Первичная индексация** (нет ни одного бандла для ноутбука): упаковка в крупные бандлы согласно стратегии первичной индексации (см. ниже)
   - **Непрерывная индексация** (бандлы уже есть): добавление в `active_delta` с каскадным сбросом

#### Стратегия первичной индексации:

Сортируем items по `published_at`. Последовательно пакуем в бандлы максимального размера:
- Пока накапливается ≥ 480k слов → создаём `frozen_full` бандл, загружаем в NLM
- Если накопилось ≥ 240k → создаём `frozen_half`
- Если накопилось ≥ 120k → создаём `frozen_quarter` (будет склеен `ConsolidateBundlesJob`-ом)
- Если осталось ≥ 10k → инициализируем `active_quarter`
- Остаток → `active_delta`

**Атомарность original_item**: если очередной item не влезает в бандл целиком — закрываем текущий бандл, item целиком уходит в следующий.

#### Стратегия непрерывной индексации:

1. Получить или создать `active_delta` для ноутбука
2. Для каждого нового item:
   - Если `active_delta.word_count + item.word_count > ACTIVE_DELTA_MAX_WORDS`: вызвать `flushDeltaToQuarter()`
   - Добавить item в `active_delta`, инкрементировать `word_count`
3. Сохранить обновлённый MD-файл на диск, вызвать `NotebookLMService::addSourceFile()` или `NotebookLMService::addSourceText()`

#### `flushDeltaToQuarter(MdBundle $delta, Notebook $notebook)`:

1. Получить или создать `active_quarter` для ноутбука
2. Если `active_quarter.word_count + delta.word_count > ACTIVE_QUARTER_MAX_WORDS`:
   - Заморозить `active_quarter` → `frozen_quarter`
   - Создать новый `active_quarter`
3. Дописать содержимое `delta` в конец `active_quarter`
4. Обновить `active_quarter` в NLM (удалить старый источник и загрузить обновлённый файл через `NotebookLMService`)
5. Обнулить `active_delta` (очистить файл, `word_count = 0`)
6. Обновить `active_delta` в NLM (удалить источник через `NotebookLMService::deleteSource()` и создать заново при необходимости)
7. Если появилось 2 `frozen_quarter` → диспатчить `ConsolidateBundlesJob`

### 4. Хранение файлов

MD-файлы сохраняются в Storage (disk `bundles`, путь `{notebook_id}/{bundle_id}.md`). Это должен быть shared volume, доступный FastAPI. Настрой disk в `config/filesystems.php`.

При создании/обновлении бандла: сначала записать файл, потом обновить NLM, потом обновить БД.

### 5. BuildBundlesJob

Создай `App\Jobs\BuildBundlesJob`:
- Принимает `notebook_id`
- Реализует `ShouldBeUnique` (уникальный по `notebook_id`)
- В `handle()` вызывает `BundleBuilder::build($notebook)`

### 6. Scheduler

В `routes/console.php` добавь расписание:

```
// Каждые 5 минут находим ноутбуки с unbundled items и запускаем BuildBundlesJob
Schedule::call(function () {
    // Найти уникальные notebook_id через original_items с md_bundle_id IS NULL
    // Для каждого dispatch BuildBundlesJob
})->everyFiveMinutes();
```

### 7. BundleItemsService

После упаковки items в бандл — создать записи в `bundle_items` (position по порядку).

Можно встроить в `BundleBuilder` или выделить в отдельный сервис.

---

## Файловая структура

```
app/
  Services/
    BundleBuilder.php
    BundleRenderer.php
    WordCounter.php
    BundleItemsService.php
  Jobs/
    BuildBundlesJob.php
```

---

## Критерии готовности

- `BundleRenderer::render()` генерирует корректный MD с Base64URL заголовками для каждого item
- `WordCounter::count()` корректно считает слова с кириллицей
- `BundleBuilder::build()` для ноутбука без бандлов (первичная индексация): 500k слов → 1 `frozen_full` + остаток в `active_delta`
- `BundleBuilder::build()` непрерывная: добавление item-а в `active_delta` → при переполнении flush в `active_quarter`
- `frozen_quarter` накопилось 2 → `ConsolidateBundlesJob` задиспатчен
- Unit-тесты `BundleBuilder`: пороги, атомарность item, flush логика
- Unit-тест `BundleRenderer`: формат заголовка, пустая строка-разделитель, корректный Base64URL
- NotebookLMService мокируется во всех тестах
