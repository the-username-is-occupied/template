
# Постановка задачи: LLM Wiki на Laravel 13

## Концепция

Ты — Senior Laravel Developer. Твоя задача — реализовать систему персональной базы знаний по паттерну LLM Wiki (Андрей Карпати).

**Суть паттерна:** Не RAG (поиск по чанкам из сырых источников), а **компилируемая вики**. LLM один раз читает источник, извлекает знания и сохраняет их в виде взаимосвязанных Markdown-файлов. При вопросах LLM отвечает строго по вики, не перечитывая сырые файлы. Знания накапливаются (компаундятся) с каждым новым источником и каждым новым вопросом.

**Три слоя архитектуры:**
- `raw/` — неизменяемые исходные текстовые файлы. LLM только читает.
- `wiki/` — сгенерированные LLM Markdown-страницы. LLM создаёт, обновляет, связывает.
- `schema/` — файл `AGENTS.md` с правилами и конвенциями для LLM-агентов.

**Технический стек:** Laravel 13 + официальный AI SDK (`laravel/ai`).

**На этом этапе работаем только с текстом.**

---

## Глобальные технические требования

### 1. Файловая система — только фасад Laravel

Все операции с файлами вики выполняются **исключительно** через `Storage::disk('wiki')`. Никаких нативных `file_get_contents`, `fopen`, `file_put_contents`.

В `config/filesystems.php` создаётся диск:

```php
'wiki' => [
    'driver' => 'local',
    'root' => storage_path('app/knowledge'),
],
```

### 2. Мульти-проектность (userspace)

Каждый пользователь/проект имеет изолированное пространство. Пути строятся как:

```
{userspace}/raw/
{userspace}/wiki/
{userspace}/schema/
```

`userspace` — строка (slug), передаётся аргументом в команды. Все команды принимают `{userspace}` первым обязательным аргументом.

### 3. Гибкое управление провайдерами и моделями

Создаётся **единый конфигурационный сервис `App\Services\WikiConfig`** (singleton). Он — единственный источник правды о провайдерах и моделях.

Методы:
- `getTestingProvider(): array` — возвращает `[provider, model]` для отладки.
- `getProductionProvider(): array` — возвращает `[provider, model]` для продакшена.
- `getFallbackProvider(): array` — возвращает `[provider, model]` для fallback.

**Значения по умолчанию (берутся из `.env`/`config/ai.php`):**

| Режим | Провайдер | Модель | Обоснование |
|---|---|---|---|
| Testing | `openai` | `gpt-4o-mini` | Самая дешёвая (~$0.15/1M in, ~$0.60/1M out), достаточна для отладки |
| Production | `openai` | `gpt-4o` | Баланс цены (~$2.50/1M in) и качества для рабочих ответов |
| Fallback | `anthropic` | `claude-haiku-4-5-20251001` | Дешёвый fallback (~$1/1M in) при недоступности основного |

Все значения можно менять через `.env` без правки кода.

Команды принимают опции:
- `--production` (bool, default: false) — переключает на продакшен-модели.
- `--provider=` (string, optional) — ручное переопределение провайдера.

### 4. Учёт токенов и затрат

Создаётся модель `App\Models\TokenUsage` с миграцией. Поля:

| Поле | Тип | Описание |
|---|---|---|
| `id` | bigint auto_increment | Первичный ключ |
| `userspace` | string | Проект/пространство |
| `operation` | enum('ingest', 'query', 'lint') | Тип операции |
| `provider` | string | Провайдер (`openai`, `anthropic`...) |
| `model` | string | Модель (`gpt-4o-mini`...) |
| `input_tokens` | int | Потрачено входных токенов |
| `output_tokens` | int | Потрачено выходных токенов |
| `input_cost` | decimal(10,6) | Стоимость input, USD |
| `output_cost` | decimal(10,6) | Стоимость output, USD |
| `total_cost` | decimal(10,6) | Общая стоимость, USD |
| `source_file` | string nullable | Имя raw-файла (для ingest) |
| `created_at` | timestamp | Дата операции |

Создаётся **сервис `App\Services\TokenUsageLogger`** с методом:

```php
log(AgentResponse $response, array $context): void
```

- Извлекает токены из `$response->usage`.
- Рассчитывает стоимость по мапе цен в `WikiConfig`.
- Записывает запись в БД.
- `$context` содержит: `userspace`, `operation`, `provider`, `model`, `source_file` (опционально).

**Консольная команда `php artisan wiki:tokens:report`** для аналитики:
- Аргументы: `{userspace}` (обязательно).
- Опции: `--period=` (`today`, `week`, `month`, `all`), `--by-model` (флаг группировки).
- Выводит таблицу с агрегацией: кол-во запросов, суммарные токены in/out, суммарная стоимость.

---

## Модуль 1: Индексация контента (`wiki:ingest`)

### Команда

```
php artisan wiki:ingest {userspace} {filename} [--production] [--provider=]
```

- `filename` — имя файла, уже лежащего в `{userspace}/raw/`.
- Команда читает файл через `Storage::disk('wiki')->get(...)`.

### Агент `IngestAgent`

Создаётся командой `php artisan make:agent IngestAgent --structured`.

**Интерфейсы:** `Agent`, `HasStructuredOutput`.

**Системный промпт (`instructions`):**

> You are an expert knowledge extractor for a personal wiki. Your task is to read a source document and extract structured knowledge from it.
>
> Rules:
> 1. Extract all key entities and concepts from the text.
> 2. For each entity/concept, provide a clear, concise markdown description.
> 3. Identify relationships between entities/concepts.
> 4. Generate an overall summary of the source.
> 5. Do not add information not present in the source.
> 6. Use the language of the source document for all output.

**Structured Output — схема (`schema`):**

```php
public function schema(JsonSchema $schema): array
{
    return [
        'overall_summary' => $schema->string()->required(),
        'pages' => $schema->array()->items(
            $schema->object(fn($s) => [
                'title' => $s->string()->required(),
                'slug' => $s->string()->required(),
                'category' => $s->string()->enum(['entity', 'concept', 'summary'])->required(),
                'content' => $s->string()->required(),
                'linked_to' => $s->array()->items($s->string())->required(),
            ])
        )->required(),
    ];
}
```

### Логика обработки ответа (после вызова агента)

1. **Запись страниц вики:**
   - Для каждого элемента из `pages`:
     - Сформировать имя файла: `{userspace}/wiki/{slug}.md`.
     - Если файл существует — обновить его (мерж контента).
     - Если нет — создать новый.
   - Каждый файл должен содержать **YAML frontmatter**:
     ```yaml
     ---
     title: "Название"
     category: entity|concept|summary
     sources:
       - "имя-raw-файла"
     updated_at: "YYYY-MM-DD"
     ---
     ```
   - Затем `content` (Markdown).
   - Затем секция `## Связанные заметки` с wiki-ссылками в формате `[[slug]]`.

2. **Обновление `index.md`:**
   - Файл `{userspace}/wiki/index.md`.
   - Если не существует — создать.
   - Структура:
     ```markdown
     # Индекс вики
     
     ## Источники
     - [[raw-файл]] — краткое описание из overall_summary
     
     ## Сущности
     - [[entity-slug]] — однострочное описание
     
     ## Концепции
     - [[concept-slug]] — однострочное описание
     ```
   - При обновлении: добавить новые страницы в соответствующие секции, обновить описания существующих.

3. **Обновление `log.md`:**
   - Файл `{userspace}/wiki/log.md`.
   - Если не существует — создать.
   - Добавить запись в конец файла:
     ```markdown
     ## [YYYY-MM-DD] ingest | Название raw-файла
     - Созданы страницы: [[slug1]], [[slug2]]...
     - Обновлены страницы: [[slug3]]...
     - Операция: ingest
     - Провайдер: {provider}
     - Модель: {model}
     - Токенов: {total_tokens}, Стоимость: ${total_cost}
     ```

4. **Логирование токенов:**
   - Вызвать `TokenUsageLogger::log($response, [...])` с `operation = 'ingest'` и `source_file`.

### Вывод команды в консоль

```
Провайдер: OpenAI
Модель: gpt-4o-mini
Токенов: 1,234 in / 567 out
Стоимость: $0.0005

Созданы страницы:
  - kvantovye-vychisleniya (concept)
  - kubit (entity)
  - superpoziciya (concept)

Обновлены страницы:
  - kvantovaya-mehanika (concept)

index.md обновлён
log.md обновлён
```

---

## Модуль 2: Вопросно-ответный поиск по вики (`wiki:ask`)

### Инструменты навигации

#### `ReadIndexTool`

Создаётся командой `php artisan make:tool ReadIndexTool`.

Интерфейс: `Tool`.

**Конструктор:** принимает `string $userspace`.

**description():**
> Reads the wiki index file (index.md) for the current userspace. Returns a list of all available wiki pages with their categories and descriptions. Always use this tool first when answering a question to understand what pages exist.

**schema():** без параметров.

**handle():**
- Читает `Storage::disk('wiki')->get('{userspace}/wiki/index.md')`.
- Если файла нет — возвращает "Индекс пуст".
- Возвращает содержимое как строку.

#### `ReadWikiPageTool`

Создаётся командой `php artisan make:tool ReadWikiPageTool`.

Интерфейс: `Tool`.

**Конструктор:** принимает `string $userspace`.

**description():**
> Reads the full content of a specific wiki page by its slug. Use after ReadIndexTool to get detailed information. Pass the exact page slug as shown in the index.

**schema():**
```php
public function schema(JsonSchema $schema): array
{
    return [
        'page' => $schema->string()->required(),
    ];
}
```

**handle(Request $request):**
- Строит путь: `{userspace}/wiki/{$request['page']}.md`.
- Если файл не найден — "Страница не найдена".
- Возвращает содержимое как строку.

### Агент `QueryAgent`

Создаётся командой `php artisan make:agent QueryAgent`.

Интерфейсы: `Agent`, `HasTools`.

**Конструктор:** принимает `string $userspace`.

**Системный промпт (`instructions`):**

> You are an expert at answering questions using a personal knowledge base (wiki).
>
> **Rules:**
> 1. **Search first.** Always call `ReadIndexTool` first to understand available pages.
> 2. **Read relevant pages.** Use `ReadWikiPageTool` to read all potentially relevant pages before answering.
> 3. **Only facts from wiki.** Answer using ONLY information found in wiki pages. Never add external knowledge as new facts.
> 4. **Honest "I don't know".** If no wiki page contains the answer, respond: "В вики нет информации по этому вопросу." Do not guess.
> 5. **Always cite sources.** End every answer with `**Источники:**` listing ONLY the wiki pages actually used for the answer. Format: `- [[page-slug]]`.
> 6. **Inline citations.** Where possible, cite facts inline: `(см. [[page-slug]])`.

**Метод `tools()`:**
```php
public function tools(): iterable
{
    return [
        new ReadIndexTool($this->userspace),
        new ReadWikiPageTool($this->userspace),
    ];
}
```

### Команда `wiki:ask`

```
php artisan wiki:ask {userspace} "Текст вопроса" [--production] [--provider=]
```

**Логика:**
1. Определить провайдера/модель через `WikiConfig` (с учётом `--production`).
2. Создать экземпляр `QueryAgent($userspace)`.
3. Вызвать `$agent->prompt('вопрос')`.
4. Вывести ответ в консоль.
5. Вызвать `TokenUsageLogger::log(...)` с `operation = 'query'`, `source_file = null`.
6. Спросить: `Сохранить ответ в вики? [y/N]`.
   - Если **y**: спросить slug, сохранить страницу в `{userspace}/wiki/{slug}.md` с категорией `synthesis`, обновить `index.md` и `log.md`.

### Вывод команды в консоль

```
Провайдер: OpenAI
Модель: gpt-4o
Токенов: 234 in / 890 out
Стоимость: $0.0028

---
[текст ответа]

**Источники:**
- [[kvantovye-vychisleniya]]
- [[kubit]]
---

Сохранить ответ в вики? [y/N]:
```

---

## Модуль 3: Линтер вики (`wiki:lint`)

### Команда

```
php artisan wiki:lint {userspace}
```

### Логика (без LLM, чисто проверка файловой системы)

1. Просканировать все `.md` файлы в `{userspace}/wiki/`, кроме `index.md` и `log.md`.

2. Для каждого файла проверить:
   - Наличие корректного YAML frontmatter с полями `title` и `category`.
   - Наличие секции `## Связанные заметки`.
   - Валидность всех wiki-ссылок `[[...]]` — что указанные slug-и существуют как файлы.

3. Проверить `index.md`:
   - Все ли страницы из файловой системы упомянуты в индексе.
   - Нет ли в индексе ссылок на несуществующие страницы.

4. Вывести отчёт в консоль:
   - Количество проверенных страниц.
   - Количество проблем.
   - Список битых ссылок.
   - Список страниц без frontmatter.
   - Список "сирот" (файлы, не упомянутые в `index.md`).
   - Список "мёртвых ссылок" в индексе (ведут на несуществующие файлы).

5. Добавить запись в `log.md`:
   ```markdown
   ## [YYYY-MM-DD] lint
   - Проверено страниц: X
   - Найдено проблем: Y
   - Битых ссылок: Z
   - Сирот: W
   ```

### Вывод команды в консоль

```
Проверено страниц: 12
Найдено проблем: 4

⚠ Битые ссылки:
  - kvantovye-vychisleniya.md → [[neosushestvuuschaya-stranica]]
  - kubit.md → [[superpoziciya]] (файл не найден)

⚠ Страницы без frontmatter:
  - kakaya-to-stranica.md

⚠ Сироты (нет в index.md):
  - novaya-zametka.md

log.md обновлён
```

---

## Модуль 4: Инициализация проекта (`wiki:init`)

### Команда

```
php artisan wiki:init {userspace}
```

### Логика

1. Создать структуру директорий через `Storage::disk('wiki')`:
   - `{userspace}/raw/`
   - `{userspace}/wiki/`
   - `{userspace}/schema/`

2. Создать пустые начальные файлы:
   - `{userspace}/wiki/index.md` с заголовком `# Индекс вики`.
   - `{userspace}/wiki/log.md` с заголовком `# Журнал операций` и первой записью о создании.
   - `{userspace}/schema/AGENTS.md` с начальным содержимым:

     ```markdown
     # AGENTS.md — Схема вики
     
     ## Структура директорий
     - `raw/` — исходные файлы (только чтение)
     - `wiki/` — сгенерированные страницы (LLM пишет)
     - `schema/` — этот файл с правилами
     
     ## Правила именования
     - Слаг страницы: транслитерация или перевод на английский, строчные буквы, дефисы вместо пробелов
     - Имя файла: `{slug}.md`
     
     ## Frontmatter страниц вики
     ```yaml
     ---
     title: "Человекочитаемое название"
     category: entity|concept|summary|synthesis
     sources:
       - "имя-raw-файла"
     updated_at: "YYYY-MM-DD"
     ---
     ```
     
     ## Правила поддержки
     - При каждом ingest: создавать/обновлять страницы, актуализировать index.md и log.md
     - При каждом вопросе: искать ответ только в вики
     - Lint: регулярно проверять целостность ссылок
     - Связи: всегда указывать двусторонние ссылки между страницами
     ```

3. Вывести сообщение об успешной инициализации.

---

## Сводка всех файлов для реализации

| Файл | Назначение |
|---|---|
| `config/filesystems.php` | Добавить диск `wiki` |
| `config/ai.php` | Настройки провайдеров (уже есть после установки SDK) |
| `app/Services/WikiConfig.php` | Управление провайдерами/моделями и ценами |
| `app/Services/TokenUsageLogger.php` | Логирование токенов и затрат |
| `app/Models/TokenUsage.php` | Модель для таблицы `token_usages` |
| `app/Ai/Agents/IngestAgent.php` | Агент извлечения знаний |
| `app/Ai/Agents/QueryAgent.php` | Агент вопросно-ответного поиска |
| `app/Ai/Tools/ReadIndexTool.php` | Инструмент чтения индекса |
| `app/Ai/Tools/ReadWikiPageTool.php` | Инструмент чтения страницы вики |
| `app/Console/Commands/WikiInit.php` | Команда `wiki:init` |
| `app/Console/Commands/WikiIngest.php` | Команда `wiki:ingest` |
| `app/Console/Commands/WikiAsk.php` | Команда `wiki:ask` |
| `app/Console/Commands/WikiLint.php` | Команда `wiki:lint` |
| `app/Console/Commands/WikiTokensReport.php` | Команда `wiki:tokens:report` |

---

## Порядок реализации (рекомендуемый)

1. **Установка и база:** `composer require laravel/ai`, миграции SDK + миграция `token_usages`.
2. **Инфраструктура:** `WikiConfig`, `TokenUsageLogger`, диск `wiki`.
3. **Инициализация:** Команда `wiki:init`.
4. **Инструменты:** `ReadIndexTool`, `ReadWikiPageTool`.
5. **Индексация:** `IngestAgent` + команда `wiki:ingest`.
6. **QA:** `QueryAgent` + команда `wiki:ask`.
7. **Линтер:** Команда `wiki:lint`.
8. **Аналитика:** Команда `wiki:tokens:report`.
9. **Тестирование и отладка** на дешёвых моделях (`--production` не ставим).

---

## Критерии приёмки (составь простые тесты для приемки)

- [ ] `wiki:init test-project` создаёт структуру и начальные файлы.
- [ ] `wiki:ingest test-project my-article.md` читает файл, извлекает сущности/концепты, создаёт страницы, обновляет index/log.
- [ ] `wiki:ask test-project "вопрос"` отвечает строго по вики со ссылками. На вопрос вне вики отвечает "нет информации".
- [ ] `wiki:lint test-project` находит битые ссылки, сироты, отсутствие frontmatter.
- [ ] `wiki:tokens:report test-project --period=all` показывает агрегированную статистику.
- [ ] Все операции логируют токены и стоимость в `token_usages`.
- [ ] `--production` переключает на `gpt-4o` / продакшен-модели.
- [ ] Все файловые операции через `Storage::disk('wiki')`.
- [ ] Fallback-провайдер срабатывает при недоступности основного.

## Приммечания
Команды должны быть тонкими. Логика в сервисах, команда же отвечает только за консольный слой
```
