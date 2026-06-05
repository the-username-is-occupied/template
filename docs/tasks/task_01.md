# Task 01
status: uncompete

## Description

- Необходи реализовать fast api для notebooklm-py как сервис в docker compose (сейчас в compose.yml это notebooklm).
 **FastAPI** — тонкий stateless исполнитель. Держит пул инициализированных
`NotebookLMClient` в памяти, маршрутизирует входящие запросы по `account_id`,
не принимает никаких решений о том, какой аккаунт использовать.


- в app/services  реализовать NLM Service через который laravel общается с fastapi. в докблоках указать что возвращается 

## fast api Endpoints 

Реализовать endpoints для fast api на основе /docs/python-api.md. 


- NotebooksAPI (`client.notebooks`)
- SourcesAPI (`client.sources`)
- ChatAPI (`client.chat`) (для ask - возврат json)
- SettingsAPI (`client.settings`)
- SharingAPI (`client.sharing`)

Остальные пока не нужны. 

В /docs добавить NLM Fast api md файл с сигнатруами fast api методами

## Account Lifecycle

Один shared volume cookies_data монтируется и в Laravel и в FastAPI. Laravel получает storage_state.json от администратора, пишет его на диск по пути /{account_uuid}/storage_state.json. Потом вызывает FastAPI POST /accounts — FastAPI читает файл с диска, инициализирует клиент с keepalive=n, добавляет в _clients. Никакого рестарта.
Дальше keepalive сам обновляет этот файл по мере ротации токенов. Laravel его больше не трогает.
yaml# docker-compose.yml
services:
  app:
    volumes:
      - cookies_data:/cookies   # Laravel пишет сюда при добавлении аккаунта

  fastapi:
    volumes:
      - cookies_data:/cookies   # FastAPI читает отсюда и обновляет (keepalive)

volumes:
  cookies_data:                 # один volume, оба сервиса видят одно и то же

Жизненный цикл добавления аккаунта
Админ загружает storage_state.json через Laravel UI
        ↓
Laravel: сохранить запись в google_accounts (id, name, pool, status=initializing)
        ↓
Laravel: записать файл → /cookies/{account_id}/storage_state.json
        ↓
Laravel: POST /accounts на FastAPI {account_id, cookie_path, pool}
        ↓
FastAPI: NotebookLMClient.from_storage(path, keepalive=n).__aenter__()
        ↓
FastAPI: добавить в _clients[account_id]
        ↓
FastAPI: ответить 201 Created
        ↓
Laravel: обновить статус → active

Старт FastAPI после рестарта
При рестарте контейнера FastAPI не знает что было в памяти. Поэтому при старте он запрашивает все активные аккаунты из Postgres и инициализирует клиентов из файлов которые уже лежат на shared volume — они никуда не делись.
FastAPI startup:
  для каждого → from_storage(account.cookie_path, keepalive=n)
Файлы на volume персистентны между рестартами — keepalive постоянно их обновляет, поэтому после рестарта клиенты стартуют с уже свежими токенами.


реализовать нужное в fastapi
---

## Health Monitoring

FastAPI предоставляет `/health/accounts`. Laravel поллит и помечает деградировавшие аккаунты.

**Индикатор здоровья сессии:** `mtime` файла `storage_state.json`. Должен обновляться каждые ~600 сек пока keepalive работает. Stale mtime = деградация сессии.

## DB: google_accounts (реализовать через laravel eloquent model)

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

## Примечания
- **Error handler:** общий перехватчик ошибок, отвечает с кодом и сообщением
- **Response time** каждый ответ от endpoint вклчает поле с временем выполнения запроса
- **Keepalive:** каждый клиент инициализируется с `keepalive=n`, где n рандомное значение от 450 до 600. Это запускает фоновую задачу, которая ротирует `__Secure-1PSIDTS` через `accounts.google.com/RotateCookies` каждые ~n сек. **Критично:** чистый RPC-трафик к `notebooklm.google.com` не триггерит ротацию токена — без keepalive сессия молча деградирует.