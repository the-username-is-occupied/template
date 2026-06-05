# Task 01
status: uncompete

## Description

- Необходи реализовать fast api для notebooklm-py как сервис в docker compose (сейчас в compose.yml это notebooklm).
 **FastAPI** —  Держит пул инициализированных
`NotebookLMClient` в памяти, маршрутизирует входящие запросы по `account_id` (uuid из tech_accounts),
не принимает никаких решений о том, какой аккаунт использовать.


- в app/services  реализовать NLM Service через который laravel общается с fastapi. в докблоках указать формат ответа. Использовать DTO 

## fast api Endpoints 

Реализовать rest api на основе всех доступных методов для секций из docs/notebook-py/python-api.md:


- `NotebooksAPI (`client.notebooks`)` после 850 линии
- `SourcesAPI (`client.sources`)` после 908 линии
- `ChatAPI (`client.chat`)` (для ask - возврат json)
- `SettingsAPI (`client.settings`)`
- `SharingAPI (`client.sharing`)`

### Пример маппинга методов на endpoints (NotebooksAPI)

| notebooklm-py method | FastAPI endpoint | HTTP метод | Параметры запроса | Ответ |
|---------------------|------------------|-------------|-------------------|--------|
| `client.notebooks.list()` | `/accounts/{account_id}/notebooks` | GET | - | `{response_time_ms: int, notebooks: Notebook[]}` |
| `client.notebooks.create(title)` | `/accounts/{account_id}/notebooks` | POST | `{title: string}` | `{response_time_ms: int, notebook: Notebook}` |
| `client.notebooks.get(notebook_id)` | `/accounts/{account_id}/notebooks/{notebook_id}` | GET | - | `{response_time_ms: int, notebook: Notebook}` |
| `client.notebooks.delete(notebook_id)` | `/accounts/{account_id}/notebooks/{notebook_id}` | DELETE | - | `{response_time_ms: int, success: bool}` |
| `client.notebooks.rename(notebook_id, new_title)` | `/accounts/{account_id}/notebooks/{notebook_id}` | PUT | `{title: string}` | `{response_time_ms: int, notebook: Notebook}` |

**Пример JSON ответа для list():**
```json
{
  "response_time_ms": 1234,
  "notebooks": [
    {
      "id": "notebook-123",
      "title": "My Research",
      "created_at": "2026-06-05T10:00:00Z",
      "sources_count": 5,
      "is_owner": true
    }
  ]
}
```

**Пример JSON запроса для create():**
```json
POST /accounts/{account_id}/notebooks
{
  "title": "New Notebook"
}
```

Схемы запрос/ответ для FAST API выработать на основе указаных  Method | Parameters | Returns и маршрутизации по `account_id`
docs/notebook-py/python-api.md так же содержит Data Types (с 1621 строки) для типов из Returns

## Account Lifecycle

вызывает FastAPI POST /accounts — FastAPI читает файл с диска, инициализирует клиент с keepalive=n, добавляет в _clients. Никакого рестарта.
Дальше keepalive сам обновляет этот файл по мере ротации токенов. 

Жизненный цикл добавления аккаунта

Админ загружает storage_state.json через Laravel UI (уже реализовано в laravel)
        ↓
Laravel: POST /accounts на FastAPI
        ↓
FastAPI: инициализация async with NotebookLMClient.from_storage(path, keepalive=n) as client
        ↓
FastAPI: добавить в _clients[account_id]
        ↓
FastAPI: проверить статус
        ↓
FastAPI: ответить 201 Created с статусом
        ↓
Laravel: обновить статус

Старт FastAPI после рестарта
При рестарте контейнера FastAPI не знает что было в памяти. Поэтому при старте он запрашивает все активные аккаунты из Postgres tech_accounts и инициализирует клиентов из файлов 


---

## Health Monitoring

FastAPI предоставляет `/health/accounts` . Laravel поллит и помечает деградировавшие аккаунты через статус в tech_accounts и ошибкой в лог.

Т.е. отправляет  массив account_id, получается массив [account_id => healthy | unhealthy]
**Индикатор здоровья сессии:** `mtime` файла `storage_state.json`. Должен обновляться каждые ~600 сек пока keepalive работает. Stale mtime = деградация сессии.

## Тестирование

Написать один laravel тест для тестирования fast api. Только для ручного запуска

storage_state.json для тестов лежит в /.temp/storage_state.json (папка проекта)
создать аккаунт из него через app/Domain/TechAccount/TechAccountService.php и тестировать с этим аккаунтом
Если файл не найден, не запускать тесты и сообщить пользователю 

## Обработка ошибок


Все исключения наследуются от `NotebookLMError` — это base class, введённый в одном из обновлений как централизованная иерархия.


`RPCError`, `SourceError`, `SourceProcessingError`, `SourceTimeoutError`, `SourceNotFoundError` — это старая версия API. В актуальной иерархия расширена.


| Исключение | Атрибуты | Когда |
|---|---|---|
| `RateLimitError` | `retry_after: int` | 429 от Google |
| `AuthError` | — | сессия истекла |
| `ValidationError` | — | неверные параметры |
| `ConfigurationError` | — | проблемы конфига |
| `NetworkError` | — | сеть недоступна |
| `NotebookLimitError` | — | лимит ноутбуков достигнут |
| `NotebookLMError` | — | base, всё остальное |

Транспортный уровень (внутренний, не public API): `TransportAuthExpired`, `TransportRateLimited`, `TransportServerError` — их ловить не нужно, они оборачиваются в публичные исключения через `RetryMiddleware`.

Писать в консоль FAST API ошибку для отладки сервиса
---

## Пример поймать ошибку

```python
from notebooklm import (
    NotebookLMError,
    RateLimitError,
    AuthError,
    NetworkError,
    NotebookLimitError,
)

try:
    result = await client.chat.ask(notebook_id, question)
except RateLimitError as e:
    # e.retry_after — секунды до следующего ретрая
    raise HTTPException(429, detail={"retry_after": e.retry_after})
except AuthError:
    # сессия умерла — пометить аккаунт unhealthy
    await mark_account_degraded(account_id)
    raise HTTPException(503, detail="account session expired")
except NetworkError:
    raise HTTPException(503, detail="notebooklm unreachable")
except NotebookLMError as e:
    # всё остальное
    raise HTTPException(500, detail=str(e))
```

Fast api должен ловить исключения и отвечать laravel с кодом и сообщением

### Формат JSON ответа об ошибке
FastAPI должен возвращать единый JSON-формат для всех ошибок, например:

```json
{
  "error": "AuthError",
  "message": "account session expired",
  "account_id": "uuid",
  "response_time_ms": 312,
  "retry_after": 60
}
```

- `error` — строковый тип имени ошибки из notebooklm-py
- `message` — читаемое сообщение для логов и трассировки
- `account_id` — uuid аккаунта, к которому относится ошибка
- `response_time_ms` — время выполнения запроса на FastAPI
- `retry_after` — необязательное поле для `RateLimitError`

Для общих ошибок без account_id допускается возвращать:

```json
{
  "error": "NotebookLMError",
  "message": "unexpected failure",
  "response_time_ms": 250
}
```

### Инициализация аккаунта и ошибка при старте
Если при `POST /accounts` инициализация клиента из `storage_state.json` не удалась, FastAPI должен ответить 500 и вернуть JSON в формате:

```json
{
  "error": "AccountInitializationError",
  "message": "failed to initialize account from storage_state.json",
  "account_id": "uuid",
  "response_time_ms": 473
}
```

Такая ошибка должна логироваться в консоль для отладки сервиса и позволять Laravel обновить статус аккаунта как `inactive` или `degraded`.

## Примечания
- Fast API должен находиться в internal сети. Авторизации между Laravel и FastAPI нет.
- **Error handler:** общий перехватчик ошибок от notebooklm-py, отвечает ларавелю с кодом и сообщением
- **Response time** каждый ответ от endpoint вклчает поле с временем выполнения запроса
- **Keepalive:** каждый клиент инициализируется с `keepalive=n`, где n рандомное значение от 450 до 600. notebooklm-py самостоятельно обновляет файлы и поддерживает  keepalive
- при ошибке инициализации аккаунта отвечать ларавель в формате json с 500 кодом, пояснением какой аккаунт не смог инициализироваться. Так же писать в консоль для отладки сервиса