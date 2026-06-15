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