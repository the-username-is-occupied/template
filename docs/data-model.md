# Data Model

## Сущности
---

### `notebooks` (Базы знаний)
Ноутбук пользователя, соответствует ноутбуку в NLM. В продуктовом UI называется «База знаний».

| Поле | Тип | Описание |
|---|---|---|
| id | uuid | PK |
| user_id | uuid | FK → users |
| nlm_notebook_id | text | ID ноутбука в NLM |
| title | text | |
| system_prompt | text | Системный промпт для Q&A-интерфейса (опционально) |
| status | enum | `active`, `archived`, `error` |
| created_at | timestamptz | |

---

### `content_sources`
Источник, добавленный пользователем. Принадлежит пользователю, может быть переиспользован в нескольких ноутбуках.

| Поле | Тип | Описание |
|---|---|---|
| id | uuid | PK |
| user_id | uuid | FK → users |
| type | enum | `website`, `youtube_video`, `youtube_channel`, `telegram_channel`, `telegram_post`, `pdf`, `text`, `audio`, `video` |
| url | text | null для файловых типов (pdf, audio, video, text) |
| file_ref | text | null для url-based типов |
| title | text | |
| auto_update | boolean | Автоматически поллить новый контент по расписанию. Актуально для `telegram_channel`, `youtube_channel`. Default: `false` |
| extraction_status | enum | `pending`, `uploading`, `extracting`, `extracted`, `error` |
| nlm_temp_source_id | text | Временный id источника в NLM во время извлечения. Обнуляется после удаления |
| parent_source_id | uuid | FK → content_sources (nullable). Ссылка на родительский источник (например, TG-канал), если этот источник был обнаружен парсером внутри другого |
| parent_item_id | uuid | FK → original_items (nullable). Ссылка на конкретный пост/элемент, в котором была найдена ссылка на этот источник. Используется совместно с `parent_source_id` для источников с `discovery_method = auto_extracted` |
| discovery_method | enum | `manual`, `auto_extracted`. Способ обнаружения источника (пользователем или парсером) |
| review_status | enum | `pending_review`, `approved`, `rejected`. Статус подтверждения для авто-извлечённых ссылок (для `manual` всегда `approved`) |
| last_fetched_id | varchar | ID последнего полученного поста/элемента при auto_update. Обновляется до максимального ID после успешного выполнения автообновления |
| metadata | jsonb | Type-specific поля. Структура описана ниже |
| created_at | timestamptz | |

**`metadata` по типам:**

| Тип | Структура |
|---|---|
| `telegram_channel` | `{ channel_id, title, members, avatar_url, scrape_config: { limit, from_date, to_date, from_id, to_id } }` |
| `telegram_post` | `{ channel_username, post_id }` |
| `youtube_channel` | `{ channel_id, title, description, handle, avatar_url, subscribers_count, view_count, video_count }` |
| `youtube_video` | `{ video_id, duration, upload_date, channel_id }` |
| `website` | `{ domain }` |
| `pdf` | `{ page_count, file_size }` |
| `audio`, `video` | `{ duration, file_size }` |

`scrape_config` заполняется из черновика (`source_drafts.scrape_config`) в момент подтверждения пользователем. Пустые поля фильтров не включаются в объект.

**`extraction_status` по типам:**

| Статус | TG Channel | YouTube Channel | Другие |
|---|---|---|---|
| `pending` | В очереди | В очереди | В очереди |
| `uploading` | Идёт парсинг (webhook chunks) | Извлечение списка видео через YouTubeService | Загрузка в NLM |
| `extracting` | — | Получение транскриптов через NLM | Ожидание NLM |
| `extracted` | Все посты сохранены | Все транскрипты сохранены | Текст сохранён |
| `error` | Ошибка | Ошибка | Ошибка |

**Поведение:**
- Извлечение (`extraction_status`) происходит **один раз** — если источник добавляется в другой ноутбук, `original_items` уже есть
- После извлечения источник удаляется из NLM, `nlm_temp_source_id` обнуляется

---

### `source_drafts`
Персистентное состояние визарда добавления источника. Позволяет восстановить прогресс при перезагрузке страницы.

Удаляется автоматически после того, как `content_source.extraction_status` переходит в `extracted`.  
Черновики со статусом `abandoned` удаляются по TTL (7 дней).

| Поле | Тип | Описание |
|---|---|---|
| id | uuid | PK |
| user_id | uuid | FK → users |
| knowledge_base_id | uuid | FK → notebooks. NOT NULL. Передаётся при создании черновика |
| content_source_id | uuid | FK → content_sources (nullable — проставляется после подтверждения пользователем) |
| type | enum | Тип источника (совпадает с `content_sources.type`). Null до разрешения URL |
| raw_input | text | Исходный ввод пользователя (одна ссылка или несколько через пробел/перенос строки) |
| channel_meta | jsonb | Мета-данные для отображения карточки источника (название, описание, аватар, кол-во подписчиков/видео) |
| scrape_config | jsonb | Пользовательская конфигурация фильтров. Дублируется в `content_sources.metadata.scrape_config` после подтверждения |
| auto_update | boolean | Значение переключателя автообновления. Default: `false` |
| status | enum | Статус визарда (описано ниже) |
| created_at | timestamptz | |
| updated_at | timestamptz | |

**`source_drafts.status`:**

| Статус | Описание |
|---|---|
| `fetching_meta` | Идёт загрузка мета-информации об источнике (канале, URL) |
| `awaiting_confirm` | Карточка источника показана, ожидаем действий пользователя |
| `processing` | Пользователь подтвердил; идёт парсинг/извлечение. Для TG: посты приходят пачками, ссылки обнаруживаются и отображаются в реальном времени. Для YT: идёт загрузка списка видео через YouTubeService |
| `awaiting_index` | Парсинг/извлечение завершено; пользователь просматривает обнаруженные ссылки (TG) или список видео (YT) перед индексацией |
| `indexing` | Пользователь нажал «Индексировать»; задачи поставлены в очередь |
| `done` | Извлечение завершено; черновик ожидает удаления |
| `abandoned` | Визард закрыт без завершения |

**Замечания:**
- Кнопка «Индексировать» становится доступна только после перехода черновика в статус `awaiting_index` (завершение предварительной обработки). Во время `processing` кнопка не показывается — пользователь должен дождаться окончания парсинга/извлечения метаданных.
- При вводе нескольких URL (разделённых пробелом или переносом строки) для каждого создаётся отдельный `source_draft`
- Если обнаруженный URL является TG-каналом или YouTube-каналом/плейлистом, тип черновика автоматически разрешается в `telegram_channel` или `youtube_channel`

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
| parent_item_id | uuid | FK → original_items (nullable). Ссылка на родительский original_item — из какого поста/документа была взята эта ссылка |
| published_at | timestamptz | |
| char_count | int | Строгий подсчёт символов (без токенизации) |
| metadata | jsonb | |
| created_at | timestamptz | |

**Примеры:**
- TG-канал → по одному item на каждый пост
- YouTube-канал → по одному item на каждое видео
- Одиночный YouTube-видео / TG-пост → один item
- PDF, аудио, видео → один item (или несколько при разбивке)

---

### `notebooks`
> Описан выше в начале раздела «Сущности».

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
| char_count | int | Строгий подсчёт символов (без токенизации) |
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
[Визард] source_draft (создаётся при открытии визарда)
  → пользователь вводит URL → разрешение типа источника
  → загрузка метаданных канала/URL
  → пользователь задаёт фильтры и подтверждает
  → для TG: создаётся content_source, запускается парсинг (draft.status = processing)
  → для YT: загружается список видео (draft.status = processing), пользователь подтверждает список (draft.status = awaiting_index)
  → для TG/YT: при нажатии «Индексировать» (draft.status = indexing) создаётся content_source (если ещё не создан) и запускается extraction job

content_source (extraction)
  → TG: async webhook парсинг → original_items + обнаруженные ссылки (pending_review)
  → YT: транскрипты через NLM → original_items
  → Другие: загрузка в NLM → fulltext → original_items
  → extraction_status = extracted → source_draft удаляется

original_items
  → упаковываем в md_bundles (под конкретный notebook)
  → загружаем bundle в NLM (nlm_source_id)
```

## Ключевые решения

- `content_source` принадлежит пользователю, не ноутбуку — один источник может быть в нескольких ноутбуках через `notebook_content_sources`
- Извлечение происходит один раз на источник — `original_items` переиспользуются
- Бандлы создаются под каждый ноутбук отдельно — разные ноутбуки могут включать разные подмножества `original_items`
- Лимит NLM в 50 источников на ноутбук закрывается через бандлы для всех типов источников без исключения
- `parent_item_id` на `content_sources` (для auto_extracted) позволяет UI показывать «ссылка найдена в посте X» без дополнительных запросов
- `source_drafts` — единственное место хранения незавершённого состояния визарда; не используется после завершения индексации
- `auto_update` хранится на `content_source`, а не на `notebook_content_sources` — источник обновляется независимо от того, в скольких ноутбуках он используется
