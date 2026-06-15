# Task 01 — Migrations, Models & Factories

## Контекст

Закладываем фундамент Source Pipeline: все таблицы, модели, enum-типы, связи и фабрики.
Перед началом изучи `docs/data-model.md` и `docs/source-pipeline.md`.

---

## Что нужно сделать

### 1. Migrations

Создай миграции для следующих таблиц (в порядке зависимостей):

**`source_drafts`**
- `id` uuid PK
- `user_id` uuid FK → users
- `knowledge_base_id` uuid FK → notebooks (NOT NULL)
- `content_source_id` uuid FK → content_sources (nullable)
- `type` enum: `telegram_channel`, `telegram_post`, `youtube_channel`, `youtube_video`, `website`, `pdf`, `text`, `audio`, `video` (nullable — до разрешения URL)
- `raw_input` text
- `channel_meta` jsonb nullable
- `scrape_config` jsonb nullable
- `auto_update` boolean default false
- `status` enum: `fetching_meta`, `awaiting_confirm`, `processing`, `awaiting_index`, `indexing`, `done`, `abandoned`
- timestamps

**`content_sources`**
- `id` uuid PK
- `user_id` uuid FK → users
- `type` enum (те же значения что у source_drafts.type)
- `url` text nullable
- `file_ref` text nullable
- `title` text nullable
- `auto_update` boolean default false
- `extraction_status` enum: `pending`, `uploading`, `extracting`, `extracted`, `error`
- `nlm_temp_source_id` text nullable
- `parent_source_id` uuid FK → content_sources nullable
- `parent_item_id` uuid FK → original_items nullable (добавить после создания таблицы)
- `discovery_method` enum: `manual`, `auto_extracted`
- `review_status` enum: `pending_review`, `approved`, `rejected`
- `last_fetched_id` varchar nullable
- `metadata` jsonb nullable
- `error_message` text nullable
- `error_code` varchar nullable
- timestamps

**`original_items`**
- `id` uuid PK
- `content_source_id` uuid FK → content_sources
- `title` text nullable
- `full_text` text nullable
- `source_url` text nullable
- `parent_item_id` uuid FK → original_items nullable
- `published_at` timestamptz nullable
- `word_count` int default 0
- `metadata` jsonb nullable
- timestamps (только created_at)

**`md_bundles`**
- `id` uuid PK
- `notebook_id` uuid FK → notebooks
- `type` enum: `active_delta`, `active_quarter`, `frozen_quarter`, `frozen_half`, `frozen_full`
- `file_path` text nullable
- `word_count` int default 0
- `status` enum: `pending`, `uploading`, `uploaded`, `error`
- `nlm_source_id` text nullable
- `is_consolidating` boolean default false
- `error_code` varchar nullable
- timestamps

**`bundle_items`**
- `id` uuid PK
- `bundle_id` uuid FK → md_bundles
- `original_item_id` uuid FK → original_items
- `position` int
- (no timestamps)

### 2. Indexes

Добавить индексы согласно `docs/source-pipeline.md` раздел "Indexes":
- `source_drafts (user_id, status)`
- `source_drafts (content_source_id)`
- `content_sources (user_id, auto_update) WHERE auto_update = true`
- `content_sources (parent_source_id) WHERE parent_source_id IS NOT NULL`
- `content_sources (review_status) WHERE review_status = 'pending_review'`
- `original_items (content_source_id, published_at)`
- `original_items (md_bundle_id) WHERE md_bundle_id IS NULL` — поле `md_bundle_id` тоже нужно добавить в таблицу как FK → md_bundles nullable
- `md_bundles (notebook_id, status)`
- `md_bundles (nlm_source_id)`

> ⚠️ Обрати внимание: `original_items.md_bundle_id` и `content_sources.parent_item_id` создают цикличные или перекрёстные зависимости между таблицами. Решай через отдельные миграции с `->nullable()` и добавлением FK после создания обеих таблиц.

### 3. Models

Создай Eloquent-модели для всех таблиц. На каждой модели:
- Настрой `$fillable` или `$guarded`
- Используй `casts()` метод (не `$casts` property) для enum-полей — каждый enum должен быть отдельным PHP-enum классом в `app/Enums/`
- Опиши все отношения (`belongsTo`, `hasMany`, `belongsToMany`)
- На `ContentSource`: scope для `pendingReview()`, `autoUpdate()`, `approved()`
- На `MdBundle`: scope для `active()`, `consolidating()`, по типам бандлов
- На `OriginalItem`: scope для `unbundled()` (md_bundle_id IS NULL)

### 4. Factories

Создай Factory для каждой модели с реалистичными данными:
- `SourceDraftFactory` — разные статусы, типы, с/без `channel_meta`
- `ContentSourceFactory` — все типы источников, разные статусы извлечения, со states: `telegram()`, `youtube()`, `autoExtracted()`, `pendingReview()`
- `OriginalItemFactory` — с `word_count`, реалистичным `full_text`
- `MdBundleFactory` — со states по типам бандлов: `activeDelta()`, `activeQuarter()`, `frozenFull()` и т.д.
- `BundleItemFactory`

### 5. Seeder

Создай `SourcePipelineSeeder`, который создаёт демонстрационный набор данных: один TG-канал источник с несколькими `original_items` и одним `active_delta` бандлом.

---

## Критерии готовности

- `php artisan migrate` проходит без ошибок
- Все enum-классы существуют и импортируются в модели
- `php artisan test --filter SourcePipelineModelsTest` — базовые тесты моделей проходят (создание, relationships, scopes)
- Factories работают: `ContentSource::factory()->telegram()->create()` создаёт корректную запись
