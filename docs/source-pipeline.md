# Source Pipeline

## Content Source Types

| Тип | Сервис | Примечание |
|---|---|---|
| Telegram channel | TG Scraper FastAPI | Поллинг новых постов по last_id |
| YouTube channel / playlist | yt-dlp FastAPI | Извлекает список video URLs; видео добавляются в NLM как YouTube-источники |

### YouTube Flow

yt-dlp service извлекает URL видео из канала/плейлиста. Транскрипцию берёт сам NotebookLM при добавлении YouTube URL как источника.

```
yt-dlp: channel_url → [video_url_1, video_url_2, ...]
    ↓
Каждый video_url → сохраняем как original_item (type=youtube)
    ↓
Каждый video_url добавляем в NLM источник, извлекать транскрипт и паковуем в md-бандлы
```

---

## MD Bundle Strategy

### Принцип: write-once

Бандл создаётся один раз, загружается в NotebookLM и **никогда не изменяется**. Новый контент → новый бандл. Ноль переиндексаций существующих бандлов.

---

### Первичная индексация

```
Все существующие посты / транскрипции
    ↓
Сортировка по дате публикации
    ↓
Пакуем в FULL бандлы (макс. 490k символов каждый, 10k margin)
    ↓
Загружаем в NotebookLM → ждём индексации
    ↓
Freeze навсегда — больше не трогаем
```

---

### Живые обновления

Scheduler Laravel запускается каждые N минут:

```
1. Запрос к TG Scraper: новые посты с last_fetched_id
2. Сохраняем в original_items (md_bundle_id = null)
3. Обновляем content_sources.last_fetched_id

4. Проверяем буфер для этого content_source:
   unbundled_chars = SUM(LENGTH(text)) WHERE md_bundle_id IS NULL

5. Триггер создания нового дельта-бандла:
   IF unbundled_chars >= 50_000
   OR (последний дельта бандл обновлен > 24h назад AND unbundled_chars > 0):
       → компилируем md-файл
       → создаём md_bundle (status=PENDING)
       → ставим задачу в очередь на загрузку в NotebookLM

6. После загрузки:
   → обновляем notebooklm_source_id, status=INDEXED
   → проставляем md_bundle_id у упакованных original_items
```

---

## Bundle File Format

Каждый md-файл содержит посты с метаданными. Метаданные идут строго перед текстом поста (без пустой строки между ними). Посты разделяются пустой строкой.

```markdown
<!-- SOURCE_ID: tg_12345 | URL: https://t.me/channel/12345 | TS: 2024-01-15T10:30:00 -->
Текст поста 12345. Может быть многострочным.
Продолжение текста того же поста.

<!-- SOURCE_ID: tg_12346 | URL: https://t.me/channel/12346 | TS: 2024-01-15T11:00:00 -->
Текст поста 12346.

<!-- SOURCE_ID: yt_dQw4w9WgXcQ | URL: https://youtube.com/watch?v=dQw4w9WgXcQ | TS: 2024-01-10T00:00:00 -->
Транскрипция видео или описание.
```

**Формат SOURCE_ID:** `{type}_{external_id}`, например `tg_12345`, `yt_dQw4w9WgXcQ`.

---

## Citation Resolution

NotebookLM возвращает `cited_text` и `notebooklm_source_id` для каждой цитаты.

```
1. По notebooklm_source_id находим md_bundle
2. Читаем bundle file
3. Ищем cited_text substring в файле
4. Сканируем назад от позиции совпадения до первого <!-- SOURCE_ID: -->
5. Извлекаем external_id из метаданных
6. Достаём original_item WHERE external_id = extracted_id
7. Возвращаем: {url, text, published_at}
```

**Edge case: cited_text пересекает границу двух постов**

NotebookLM нарезает контент на чанки ~1-2k символов без учёта границ постов. Чанк может захватить конец одного поста и начало следующего.

Алгоритм вернёт пост, где цитата начинается (ближайший SOURCE_ID выше). Для MVP приемлемо.

Митигация: пустая строка-разделитель между постами снижает вероятность склейки в один чанк (уже заложено в формат).

---

## DB Tables

```sql
content_sources
  id                uuid pk
  knowledge_base_id fk
  type              enum(telegram, youtube)
  external_id       varchar         -- @channel или URL
  name              varchar
  last_fetched_id   varchar         -- последний tg post_id / yt video_id
  last_fetched_at   timestamp
  status            enum(active, paused, error)

original_items
  id                uuid pk
  content_source_id fk
  external_id       varchar         -- tg post_id / yt video_id
  url               varchar
  text              text
  published_at      timestamp
  md_bundle_id      uuid fk null    -- null = ещё не упакован
  created_at        timestamp

md_bundles
  id                    uuid pk
  knowledge_base_id     fk
  notebooklm_source_id  varchar null  -- null до загрузки
  type                  enum(full, delta)
  status                enum(pending, uploading, indexed, failed)
  first_item_id         uuid fk
  last_item_id          uuid fk
  char_count            int
  file_path             varchar
  uploaded_at           timestamp
  created_at            timestamp
```

### Indexes

```sql
CREATE INDEX ON original_items (content_source_id, published_at);
CREATE INDEX ON original_items (md_bundle_id) WHERE md_bundle_id IS NULL;
CREATE INDEX ON md_bundles (knowledge_base_id, status);
CREATE INDEX ON md_bundles (notebooklm_source_id);
```
