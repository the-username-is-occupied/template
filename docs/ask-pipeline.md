# Ask Pipeline — Постановка задачи

## Обзор

Пользователь задаёт вопрос → роутится на NotebookLM через свободный технический аккаунт → сохраняется в БД → резолвит цитаты к оригинальным источникам → возвращается пользователю.

---

## 1. Таблица `tech_account_usages`

Добавить миграцию и модель `TechAccountUsage`.

**Схема:**

| Поле             | Тип        | Описание                        |
|------------------|------------|---------------------------------|
| `id`             | bigint PK  |                                 |
| `tech_account_id`| bigint FK  | → `tech_accounts`               |
| `date`           | date       | День подсчёта                   |
| `count`          | integer    | Кол-во запросов за день         |
| `created_at`     | timestamp  |                                 |
| `updated_at`     | timestamp  |                                 |

**Unique constraint:** `(tech_account_id, date)`

**Инкремент** — через `upsert`:
```php
TechAccountUsage::upsert(
    [['tech_account_id' => $id, 'date' => today(), 'count' => 1]],
    uniqueBy: ['tech_account_id', 'date'],
    update:   ['count' => DB::raw('count + 1')],
);
```

---

## 2. `AccountService` — выборка аккаунта для ask

Добавить метод `getAccountForAsk(): TechAccount`.

**Логика:**
1. Взять все активные `TechAccount`
2. LEFT JOIN с `tech_account_usages` на `date = today()`
3. Выбрать аккаунт с `MIN(count)` — т.е. наименьшей нагрузкой за день
4. Если у выбранного аккаунта `count >= $account->tierLimit->daily_limit` → все аккаунты исчерпаны → бросить `DailyLimitExceededException`

```php
public function getAccountForAsk(): TechAccount
{
    $account = TechAccount::query()
        ->leftJoinRelation('todayUsage')
        ->orderByRaw('COALESCE(tech_account_usages.count, 0) ASC')
        ->first();

    if (! $account) {
        throw new DailyLimitExceededException();
    }

    $used = $account->todayUsage?->count ?? 0;

    if ($used >= $account->tierLimit->daily_limit) {
        throw new DailyLimitExceededException();
    }

    return $account;
}
```

**`DailyLimitExceededException`** перехватывается в `Handler` и возвращает `503` с сообщением:
> "Лимит запросов на сегодня исчерпан. Попробуйте завтра."

---

## 3. `AskService::ask` — основной метод

**Сигнатура:**
```php
public function ask(Notebook $notebook, string $question, ?Chat $chat = null): AskResult
```

**Шаги:**

```
1. Получить аккаунт: AccountService->getAccountForAsk()
       ↓
2. Если $chat === null → создать новый Chat {user_id, notebook_id}
       ↓
3. Сохранить user-сообщение в chat_messages:
   {chat_id, role=user, content=$question}
       ↓
4. Отправить запрос: NotebookLMService->ask($account, $notebook, $question)
       → AskResultDTO
       ↓
5. Инкрементировать usage: TechAccountUsage::upsert(...)
       ↓
6. Резолвить цитаты: $dto->resolve() → ResolvedAskResultDTO
       ↓
7. Сохранить assistant-сообщение в chat_messages:
   {chat_id, role=assistant, tech_account_id, result=$dto->toArray(), is_success=true}
       ↓
8. Вернуть ResolvedAskResultDTO
```

**Обработка ошибок от NotebookLMService:**

Если запрос бросает исключение — всё равно сохранить assistant-сообщение:
```php
{
    role:           assistant,
    tech_account_id: $account->id,
    content:        null,
    result:         null,
    is_success:     false,
}
```
Затем пробросить исключение выше.

> **Инкремент usage происходит не только при успешном ответе, любой запрос через NLM занимает слот** 

---

## 4. Модели `Chat` и `ChatMessage`

### `chats`

| Поле          | Тип       | Описание            |
|---------------|-----------|---------------------|
| `id`          | bigint PK |                     |
| `user_id`     | bigint FK | → `users`           |
| `notebook_id` | bigint FK | → `notebooks`       |
| `created_at`  | timestamp |                     |
| `updated_at`  | timestamp |                     |

### `chat_messages`

| Поле              | Тип              | Описание                                              |
|-------------------|------------------|-------------------------------------------------------|
| `id`              | bigint PK        |                                                       |
| `chat_id`         | bigint FK        | → `chats`                                             |
| `tech_account_id` | bigint FK null   | → `tech_accounts`. Только для `role=assistant`        |
| `role`            | enum             | `user` / `assistant`                                  |
| `content`         | text null        | Вопрос пользователя (`role=user`). Пусто у ассистента |
| `result`          | json null        | `AskResultDTO->toArray()`. Только у ассистента|
| `is_success`      | boolean null     | `null` у user-сообщений                               |
| `created_at`      | timestamp        |                                                       |
| `updated_at`      | timestamp        |                                                       |

### Правило сериализации

После получения ответа от NotebookLM:
```php

ChatMessage::create([
    'chat_id'          => $chat->id,
    'tech_account_id'  => $account->id,
    'role'             => 'assistant',
    'content'          => null,
    'result'           => $dto->toArray(),
    'is_success'       => true,
]);
```

---

## 5. Критерий успеха

Ask считается успешным = **ответ получен от NotebookLM**, независимо от качества содержания.
Поле `is_success = true` выставляется при получении любого валидного `AskResultDTO`.

---

## 6. Обработка ошибок

| Ситуация                              | Поведение                                                   |
|---------------------------------------|-------------------------------------------------------------|
| Все аккаунты достигли дневного лимита | `DailyLimitExceededException` → 503                         |
| NotebookLM вернул ошибку              | Сохранить `is_success=false`, пробросить исключение         |
| `$chat` не передан                    | Создать новый `Chat` автоматически                          |

---

## Зависимости (уже существуют в проекте)

- `TechAccount` + `TechAccount::tierLimit`
- `NotebookLMService::ask()` → `AskResultDTO`
- `AskResultDTO::resolve()` → `ResolvedAskResultDTO`
- `ResolvedAskResultDTO::toArray()`
- `Notebook` model