# Data Model

## Сущности

### `users`
| Поле | Тип | Описание |
|---|---|---|
| id | uuid | PK |
| email | text | |
| created_at | timestamptz | |

---

### `content_sources`
Источник, добавленный пользователем. Принадлежит пользователю, может быть переиспользован в нескольких ноутбуках.

| Поле | Тип | Описание |
|---|---|---|
| id | uuid | PK |
| user_id | uuid | FK → users |
| type | enum | `website`, `youtube_video`, `youtube_channel`, `telegram_channel`, `pdf`, `text`, `audio`, `video` |
| url | text | null для файловых типов (pdf, audio, video, text) |
| file_ref | text | null для url-based типов |
| title | text | |
| extraction_status | enum | `pending`, `uploading`, `extracting`, `extracted`, `error` |
| nlm_temp_source_id | text | Временный id источника в NLM во время извлечения. Обнуляется после удаления |
| metadata | jsonb | Type-specific поля (channel_id, page_count, duration и т.п.) |
| created_at | timestamptz | |

**Поведение:**
- Извлечение (`extraction_status`) происходит **один раз** — если источник добавляется в другой ноутбук, `original_items` уже есть
- После извлечения источник удаляется из NLM, `nlm_temp_source_id` обнуляется

---

### `original_items`
Единица извлечённого контента. Любой `content_source` может иметь несколько `original_items` (1:1 — частный случай).

| Поле | Тип | Описание |
|---|---|---|
| id | uuid | PK |
| content_source_id | uuid | FK → content_sources |
| title | text | |
| full_text | text | Полный текст, извлечённый через NLM API |
| source_url | text | Permalink конкретного item (пост, видео, страница) |
| published_at | timestamptz | |
| token_count | int | |
| metadata | jsonb | |
| created_at | timestamptz | |

**Примеры:**
- TG-канал → по одному item на каждый пост
- YouTube-канал → по одному item на каждое видео
- Одиночный YouTube-видео / TG-пост → один item
- PDF, аудио, видео → один item (или несколько при разбивке)

---

### `notebooks`
Ноутбук пользователя, соответствует ноутбуку в NLM.

| Поле | Тип | Описание |
|---|---|---|
| id | uuid | PK |
| user_id | uuid | FK → users |
| nlm_notebook_id | text | ID ноутбука в NLM |
| title | text | |
| status | enum | `active`, `archived`, `error` |
| created_at | timestamptz | |

---

### `notebook_content_sources`
Какие источники добавлены в какой ноутбук.

| Поле | Тип | Описание |
|---|---|---|
| id | uuid | PK |
| notebook_id | uuid | FK → notebooks |
| content_source_id | uuid | FK → content_sources |
| added_at | timestamptz | |

---

### `md_bundles`
Упакованный MD-файл для загрузки в NLM. Создаётся под конкретный ноутбук.

| Поле | Тип | Описание |
|---|---|---|
| id | uuid | PK |
| notebook_id | uuid | FK → notebooks |
| file_path | text | Путь к файлу на хранилище |
| token_count | int | |
| status | enum | `pending`, `uploading`, `uploaded`, `error` |
| nlm_source_id | text | ID источника в NLM после загрузки бандла |
| created_at | timestamptz | |

---

### `bundle_items`
Какие `original_items` вошли в бандл и в каком порядке.

| Поле | Тип | Описание |
|---|---|---|
| id | uuid | PK |
| bundle_id | uuid | FK → md_bundles |
| original_item_id | uuid | FK → original_items |
| position | int | Порядок внутри бандла |

---

## Пайплайн

```
content_source (добавлен пользователем)
  → загружаем в NLM (nlm_temp_source_id)
  → NLM извлекает fulltext
  → сохраняем как original_items
  → удаляем источник из NLM, обнуляем nlm_temp_source_id

original_items
  → упаковываем в md_bundles (под конкретный notebook)
  → загружаем bundle в NLM (nlm_source_id)
```

## Ключевые решения

- `content_source` принадлежит пользователю, не ноутбуку — один источник может быть в нескольких ноутбуках через `notebook_content_sources`
- Извлечение происходит один раз на источник — `original_items` переиспользуются
- Бандлы создаются под каждый ноутбук отдельно — разные ноутбуки могут включать разные подмножества `original_items`
- Лимит NLM в 50 источников на ноутбук закрывается через бандлы для всех типов источников без исключения