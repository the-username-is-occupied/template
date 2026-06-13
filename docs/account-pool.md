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
Один прокси на процесс — это ровно то, что поддерживается из коробки через env vars.

В `compose.yml`:

```yaml
fastapi-nlm:
  environment:
    HTTP_PROXY: "http://user:pass@residential-host:port"
    HTTPS_PROXY: "http://user:pass@residential-host:port"
    NO_PROXY: "localhost,postgres,redis"
```

httpx читает эти переменные автоматически — все `NotebookLMClient` внутри процесса пойдут через один прокси. Ничего дополнительно в коде делать не нужно.

`NO_PROXY` важен — без него внутренние запросы к Redis и Postgres тоже пойдут через прокси.



**3. Keepalive jitter**

Не ровно 600 сек, а случайные 580–620 сек между RotateCookies вызовами.

---

## Ask Capacity Model

Все ноутбуки создаются как **shared (public viewer)**. Любой аккаунт может делать ask на любом ноутбуке. Это позволяет распределять нагрузку:

```
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

## Tech Notebooks Management

`AccountService` является **единым шлюзом** для всех операций с техническими ноутбуками (таблица `tech_notebooks`). Это исключает race conditions и дублирование логики.

### Псевдокод AccountService для тех. ноутбуков

```php
class AccountService
{
    /**
     * Находит тех. ноутбук с максимальным количеством свободных слотов.
     * Если свободных слотов нет ни в одном существующем, пытается создать новый (Burst-режим).
     * 
     * @return array|null ['notebook' => TechNotebook, 'available_slots' => int]
     */
    public function selectTechNotebookWithCapacity(string $type): ?array
    {
        // Ищем idle или busy ноутбук, у которого есть хотя бы 1 свободный слот
        // Сортируем по убыванию свободных слотов, чтобы брать максимально возможные батчи

        
            // Если все существующие ноутбуки заполнены (full), пытаемся создать новый
            
    }

    /**
     * Создает новый тех. ноутбук на свободном аккаунте.
     */
    public function createTechNotebook(string $type, ?TechAccount $preferredAccount = null): ?TechNotebook
    {
        $account = $preferredAccount ?? $this->selectAccountForNotebookCreation();
        
        
    }

    /**
     * Захватывает distributed lock на тех. ноутбук.
     * Предотвращает множественный доступ (два job не могут одновременно загружать видео).
     */
    public function acquireTechNotebookLock(string $notebookId, string $lockedBy, int $ttlSeconds = 600): bool
    {
        // UPDATE tech_notebooks 
        // SET locked_at = NOW(), locked_by = $lockedBy, status = 'busy'
        // WHERE id = $notebookId AND locked_at IS NULL
        // RETURNING id
    }

    /**
     * Освобождает lock после успешного завершения задачи.
     */
    public function releaseTechNotebookLock(string $notebookId, string $lockedBy): void
    {
        // UPDATE tech_notebooks 
        // SET locked_at = NULL, locked_by = NULL, status = 'idle'
        // WHERE id = $notebookId AND locked_by = $lockedBy
    }

    /**
     * Атомарное обновление счетчика источников.
     */
    public function updateTechNotebookSourcesCount(string $notebookId, int $delta): void
    {
        // UPDATE tech_notebooks 
        // SET sources_count = sources_count + $delta,
        //     status = CASE 
        //         WHEN (sources_count + $delta) >= max_sources THEN 'full'
        //         ELSE 'idle'
        //     END
        // WHERE id = $notebookId
    }

    /**
     * Помечает ноутбук как деградировавший (например, при сбое очистки).
     */
    public function markNotebookDegraded(string $notebookId): void
    {
        TechNotebook::where('id', $notebookId)
            ->update([
                'status' => 'degraded',
                'locked_at' => null,
                'locked_by' => null
            ]);
    }
}
```

### Фоновые джобы для тех. ноутбуков

**`MaintainTechNotebooksPoolJob`** (запускается по крону каждые 5 минут):
- Проверяет количество тех. ноутбуков каждого типа.
- Если меньше базового пула (5 source_extractor, 1 summary_aggregator, 1 global_search) — создаёт недостающие.
- Если есть ноутбуки со статусом `degraded` — пытается пересоздать их.

**`CleanupStaleTechNotebooksJob`** (запускается по крону каждые 15 минут):
- Находит ноутбуки со статусом `busy` и `locked_at < now() - 15 minutes` (зависшие после краша job).
- Пытается очистить их через NLM API (`deleteAllSources`).
- Если очистка успешна — сбрасывает статус в `idle`, `sources_count = 0`.
- Если очистка не удалась (сессия протухла) — помечает как `degraded`.

---

## Health Monitoring

FastAPI предоставляет `/health/accounts`. Laravel поллит и помечает деградировавшие аккаунты.

**Индикатор здоровья сессии:** `mtime` файла `storage_state.json`. Должен обновляться каждые ~600 сек пока keepalive работает. Stale mtime = деградация сессии.

---

## DB: tech_accounts

```sql
id               uuid pk
name             varchar
email            varchar
pool_type        enum(free, plus, pro, ultra)
status           enum(initializing, active, inactive, banned)
cookie_path      varchar
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
