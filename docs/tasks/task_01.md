# Task 01
status: uncompete

## Description

- Необходи реализовать fast api для notebooklm-py как сервис в docker compose (сейчас в compose.yml это notebooklm).
 **FastAPI** —  Держит пул инициализированных
`NotebookLMClient` в памяти, маршрутизирует входящие запросы по `account_id`,
не принимает никаких решений о том, какой аккаунт использовать.


- в app/services  реализовать NLM Service через который laravel общается с fastapi. в докблоках указать формат ответа. Использовать DTO 

## fast api Endpoints 

Реализовать endpoints для fast api на основе docs/notebook-py/python-api.md. В нем же указаны параметры и форматы ответов

Все доступные методы для секций:

- NotebooksAPI (`client.notebooks`)
- SourcesAPI (`client.sources`)
- ChatAPI (`client.chat`) (для ask - возврат json)
- SettingsAPI (`client.settings`)
- SharingAPI (`client.sharing`)


## Account Lifecycle

вызывает FastAPI POST /accounts — FastAPI читает файл с диска, инициализирует клиент с keepalive=n, добавляет в _clients. Никакого рестарта.
Дальше keepalive сам обновляет этот файл по мере ротации токенов. 

Жизненный цикл добавления аккаунта

Админ загружает storage_state.json через Laravel UI (уже реализовано в laravel)
        ↓
Laravel: POST /accounts на FastAPI
        ↓
FastAPI: NotebookLMClient.from_storage(path, keepalive=n).__aenter__()
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

FastAPI предоставляет `/health/accounts`. Laravel поллит и помечает деградировавшие аккаунты.

**Индикатор здоровья сессии:** `mtime` файла `storage_state.json`. Должен обновляться каждые ~600 сек пока keepalive работает. Stale mtime = деградация сессии.

## Тестирование

Написать один laravel тест для тестирования fast api

storage_state.json для тестов лежит в /.temp/storage_state.json
создать аккаунт из него через app/Domain/TechAccount/TechAccountService.php и тестировать с этим аккаунтом
Если файл не найден, не запускать тесты и сообщить пользователю 

## Примечания
- Fast API должен находиться в internal сети. Авторизации между Laravel и FastAPI нет.
- **Error handler:** общий перехватчик ошибок от notebooklm-py, отвечает ларавелю с кодом и сообщением
- **Response time** каждый ответ от endpoint вклчает поле с временем выполнения запроса
- **Keepalive:** каждый клиент инициализируется с `keepalive=n`, где n рандомное значение от 450 до 600. notebooklm-py самостоятельно обновляет файлы и поддерживает  keepalive
