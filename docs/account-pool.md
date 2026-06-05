# Account Pool

## Overview

Пул технических Google-аккаунтов для взаимодействия с NotebookLM от имени Eolithic.

**Принцип:** вся логика захвата аккаунтов живёт в Laravel. FastAPI получает уже выбранный `account_id` и просто использует соответствующий клиент из памяти.

---

## Account Lifecycle

```
Админ загружает storage_state.json через Laravel UI
    ↓
Laravel: сохранить запись в google_accounts (status=initializing)
    ↓
Laravel: записать файл → /cookies/{account_id}/storage_state.json
    ↓
Laravel: POST /accounts на FastAPI {account_id, cookie_path, pool}
    ↓
FastAPI: NotebookLMClient.from_storage(path, keepalive=600).__aenter__()
    ↓
FastAPI: добавить в _clients[account_id]
    ↓
Laravel: обновить статус → active
```

**Keepalive:** каждый клиент инициализируется с `keepalive=600`. Это запускает фоновую задачу, которая ротирует `__Secure-1PSIDTS` через `accounts.google.com/RotateCookies` каждые ~600 сек. **Критично:** чистый RPC-трафик к `notebooklm.google.com` не триггерит ротацию токена — без keepalive сессия молча деградирует.

**Рестарт FastAPI:** при старте контейнера FastAPI читает все активные аккаунты из Postgres и инициализирует клиентов из файлов на shared volume.

---

## Anti-Ban Strategy

**Угроза:** datacenter IP → Google risk-scoring → сессии деградируют быстрее, аккаунты могут быть заблокированы.

### Меры

**1. Residential proxy на каждый аккаунт**

Каждый `NotebookLMClient` инициализируется с выделенным residential IP (конфигурируется на уровне `httpx.AsyncClient`).

MVP: 3-4 IP на 10 аккаунтов (2-3 аккаунта на IP). Аккаунты с одного IP обслуживают разные knowledge bases — так инцидент на одном IP не кладёт весь сервис.


**3. Keepalive jitter**

Не ровно 600 сек, а случайные 580–620 сек между RotateCookies вызовами.

---

## Ask Capacity Model

Все ноутбуки создаются как **shared (public viewer)**. Любой аккаунт может делать ask на любом ноутбуке. Это позволяет распределять нагрузку:

```
Notebook X
  Owner:  Account A
  Viewer: Account B, C, D, ...

Ask request → выбрать аккаунт с MIN(chats_today) → chat.ask(notebook_id, ...)
```

**Суммарный capacity одной knowledge base** = сумма дневных лимитов всех аккаунтов в пуле.

Пример: 10 аккаунтов × 50 чатов/день (free) = **500 asks/day** на весь пул.

---

## Account Selection

**Для создания ноутбука:** аккаунт с наименьшим `notebooks_count`.

**Для ask-запроса:** аккаунт с наименьшим `chats_today`. Атомарный инкремент через Redis:

```
INCR ask_count:{account_id}:{YYYY-MM-DD}
EXPIRE ask_count:{account_id}:{YYYY-MM-DD} 86400
```

Если все аккаунты достигли дневного лимита → 503.

---

## Health Monitoring

FastAPI предоставляет `/health/accounts`. Laravel поллит и помечает деградировавшие аккаунты.

**Индикатор здоровья сессии:** `mtime` файла `storage_state.json`. Должен обновляться каждые ~600 сек пока keepalive работает. Stale mtime = деградация сессии.

---

## DB: google_accounts

```sql
id               uuid pk
name             varchar
email            varchar
pool_type        enum(free, plus, pro, ultra)
status           enum(initializing, active, inactive, banned)
cookie_path      varchar
proxy_host       varchar        -- residential proxy для этого аккаунта
notebooks_count  int default 0
chats_today      int default 0
chats_reset_at   timestamp
last_used_at     timestamp
created_at       timestamp
```

## DB: account_tier_limits

```sql
tier                  enum(free, plus, pro, ultra) pk
notebooks_limit       int
sources_per_notebook  int
chats_per_day         int
audio_per_day         int
updated_at            timestamp      -- синхронизируется вручную
```
