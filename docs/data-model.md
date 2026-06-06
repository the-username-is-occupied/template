# Data Model

## Entity Overview

```
User
  ├── ChatSession(s)
  └── (владеет) KnowledgeBase(s)

KnowledgeBase
  ├── owner: GoogleAccount      (создал ноутбук)
  ├── viewers: GoogleAccount[]  (могут делать ask)
  ├── ContentSource(s)
  │     └── OriginalItem(s)
  │           └── MdBundle (после упаковки)
  └── MdBundle(s) → NotebookLM sources

ChatSession
  └── ChatMessage(s)
```

---

## Full Schema

```sql
-- ─────────────────────────────────────────
-- Knowledge Bases
-- ─────────────────────────────────────────

knowledge_bases
  id                   uuid pk
  user_id              fk → users
  title                varchar
  notebook_id          varchar        -- NotebookLM notebook ID
  owner_account_id     fk → google_accounts
  status               enum(indexing, ready, updating, error)
  created_at           timestamp
  updated_at           timestamp

-- ─────────────────────────────────────────
-- Content Sources
-- ─────────────────────────────────────────

content_sources
  id                   uuid pk
  knowledge_base_id    fk → knowledge_bases
  type                 enum(telegram, youtube)
  external_id          varchar        -- @channel или URL
  name                 varchar
  last_fetched_id      varchar        -- последний tg post_id / yt video_id
  last_fetched_at      timestamp
  status               enum(active, paused, error)

original_items
  id                   uuid pk
  content_source_id    fk → content_sources
  external_id          varchar        -- tg post_id / yt video_id
  url                  varchar
  text                 text
  published_at         timestamp
  md_bundle_id         uuid fk null   -- null = ещё не упакован в бандл
  created_at           timestamp

md_bundles
  id                       uuid pk
  knowledge_base_id        fk → knowledge_bases
  notebooklm_source_id     varchar null  -- null до загрузки в NLM
  type                     enum(full, delta)
  status                   enum(pending, uploading, indexed, failed)
  first_item_id            uuid fk → original_items
  last_item_id             uuid fk → original_items
  char_count               int
  file_path                varchar
  uploaded_at              timestamp
  created_at               timestamp

-- ─────────────────────────────────────────
-- Chat
-- ─────────────────────────────────────────

chat_sessions
  id                   uuid pk
  user_id              fk → users
  knowledge_base_id    fk → knowledge_bases
  created_at           timestamp
  last_message_at      timestamp

chat_messages
  id                   uuid pk
  chat_session_id      fk → chat_sessions
  role                 enum(user, assistant)
  content              text
  raw_citations        jsonb     -- сырые ChatReference[] от notebooklm-py
  resolved_citations   jsonb     -- после citation resolution
  account_id           fk → google_accounts
  created_at           timestamp
```

---

## Indexes

```sql
-- original_items
CREATE INDEX ON original_items (content_source_id, published_at);
CREATE INDEX ON original_items (md_bundle_id) WHERE md_bundle_id IS NULL;

-- md_bundles
CREATE INDEX ON md_bundles (knowledge_base_id, status);
CREATE INDEX ON md_bundles (notebooklm_source_id);

-- chat_messages
CREATE INDEX ON chat_messages (chat_session_id, created_at);

```

---

## resolved_citations JSON Structure

```json
[
  {
    "citation_number": 1,
    "cited_text": "фрагмент из ответа NLM",
    "source": {
      "external_id": "tg_12345",
      "url": "https://t.me/channel/12345",
      "published_at": "2024-01-15T10:30:00Z",
      "text_preview": "первые 200 символов поста..."
    }
  }
]
```
