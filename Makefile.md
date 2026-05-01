# Makefile / Taskfile — команды управления проектом

## Окружения

Все compose-команды поддерживают переключение dev/prod:

```bash
make build              # dev (по умолчанию)
make build ENV=prod     # prod (docker-compose.yml + docker-compose.prod.yml)
make up ENV=prod
make deploy             # build prod + migrate + optimize
```

Для Taskfile:
```bash
task build              # dev
task build ENV=prod     # prod
task deploy             # build prod + migrate + optimize
```

## Compose / Оркестрация

| Команда   | Описание                                    |
|-----------|---------------------------------------------|
| `build`   | Собрать и запустить контейнеры              |
| `up`      | Запустить контейнеры                        |
| `restart` | Перезапустить все контейнеры                |
| `stop`    | Остановить все контейнеры                   |
| `down`    | Остановить и удалить контейнеры (с томами)  |
| `logs`    | Показать логи (tail -f)                     |
| `ps`      | Показать запущенные контейнеры              |

## Приложение / Контейнер

| Команда           | Описание                              |
|-------------------|---------------------------------------|
| `shell`           | Bash в контейнере приложения          |
| `composer-install`| Установить PHP-зависимости            |
| `npm-install`     | Установить Node-зависимости           |
| `key-generate`    | Сгенерировать APP_KEY                 |
| `storage-link`    | Создать симлинк storage               |
| `cache-clear`     | Очистить кэш                          |
| `optimize`        | Закэшировать config/routes/views      |
| `optimize-clear`  | Сбросить кэш config/routes/views      |

## База данных

| Команда        | Описание                          |
|----------------|-----------------------------------|
| `migrate`      | Запустить миграции                |
| `migrate-fresh`| Пересоздать все таблицы           |
| `seed`         | Запустить сидеры                  |
| `db-setup`     | Миграции + сидеры                 |
| `db-fresh`     | Fresh migrate + seed              |

## Качество / CI

| Команда      | Описание                                   |
|--------------|--------------------------------------------|
| `pint`       | Исправить стиль кода (Pint)                |
| `pint-check` | Проверить стиль без исправлений            |
| `rector`     | Запустить Rector                           |
| `rector-dry` | Rector в режиме dry-run                    |
| `insights`   | PHP Insights                               |
| `stan`       | PHPStan статический анализ                 |
| `test`       | Запустить тесты параллельно                |
| `check`      | Все проверки разом (без мутаций в коде)    |

## Утилиты

| Команда   | Описание                        |
|-----------|---------------------------------|
| `tinker`  | Открыть Laravel Tinker          |
| `swagger` | Сгенерировать Swagger-документы |
| `horizon` | Статус Horizon                  |

## Сценарии

| Команда  | Описание                                          |
|----------|---------------------------------------------------|
| `init`   | Полная инициализация проекта (dev)                |
| `deploy` | Prod-деплой: build prod + migrate + optimize      |
