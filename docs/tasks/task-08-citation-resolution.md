# Task 08 — Citation Resolution

## Контекст

Реализуем механизм резолвинга цитат: когда NLM возвращает ответ с фрагментами текста из бандла,
нужно найти конкретные `original_item`-ы и вернуть их `source_url` и мета-данные.

`source_id` из `ChatReferenceDTO` соответствует `nlm_source_id` из `MdBundle`.

---

## Что нужно сделать

### 1. CitationResolver

Создай `App\Services\CitationResolver` с методом:

```php
public function resolve(AskResultDTO $askResult): ResolvedAskResultDTO
```

Метод принимает `AskResultDTO` (ответ NLM с массивом цитат) и возвращает `ResolvedAskResultDTO`
с полем `answer` и массивом `CitationData[]`.

#### Алгоритм обработки одной цитаты (точно по `docs/source-pipeline.md`):

**Шаг 0 — Очистка `cited_text` от метаданных**

`cited_text` может содержать встроенные метаданные в формате: `<Base64URL> {"..."}`.
Перед поиском необходимо очистить текст:
- Найти все вхождения паттерна `[A-Za-z0-9_-]{22} \{"[^}]*"\}` (Base64URL + пробел + JSON-объект, начинающийся `{"` и заканчивающийся `"}`)
- Удалить и Base64URL-токен, и следующий за ним JSON целиком
- Сохранить очищенный текст в `CitationData::cited_text_clean`

**Шаг 1 — Определение стратегии поиска**

Проверить, начинается ли `cited_text` с Base64URL (паттерн `^[A-Za-z0-9_-]{22}(\s|$)`):
- **Да** → ID уже известен, извлечь первые 22 символа и перейти сразу к шагу 5 (поиск по файлу не нужен)
- **Нет** → выполнить полнотекстовый поиск (шаги 2–4)

**Шаг 2 — Найти `MdBundle` по `nlm_source_id`**

`nlm_source_id` берётся из `ChatReferenceDTO::source_id`.

**Шаг 3 — Прочитать MD-файл бандла с диска**

```php
Storage::get($bundle->file_path)
```

Оптимизация: файл бандла читается с диска **один раз** для всех цитат с одинаковым `nlm_source_id` (кешировать в памяти в рамках одного вызова `resolve()`).

**Шаг 4 — Найти позицию очищенного `cited_text` в файле**

Поиск через регулярное выражение по `cited_text_clean`.
Если не найдено — пропустить цитату (залогировать warning), не включать в результат.

**Шаг 5 — Извлечь Base64URL-идентификатор item-а**

Сканировать назад от позиции совпадения до первого вхождения `>` в начале строки.
Считать следующие 22 символа до первого пробела.

**Шаг 6 — Валидировать и декодировать идентификатор**

- Валидировать регулярным выражением `^[A-Za-z0-9_-]{22}$`
- Декодировать Base64URL обратно в UUID (reverse операция из `BundleRenderer::encodeItemId()`)

**Шаг 7 — Найти `OriginalItem` и вернуть данные**

```php
OriginalItem::find($decodedUuid)
```

Если на любом шаге произошла ошибка (файл не найден, UUID невалидный, item не найден) —
пропустить цитату, залогировать warning.

---

### 2. CitationData DTO

Создай `App\Domain\Citations\DTOs\CitationData` через `spatie/laravel-data`:

| Поле | Тип | Описание |
|---|---|---|
| `source_url` | `string` | Permalink оригинального поста/видео/страницы |
| `title` | `?string` | Заголовок item-а |
| `published_at` | `?string` | Дата публикации ISO 8601 |
| `source_type` | `string` | Тип источника (`telegram_channel`, `youtube_video`, etc.) |
| `content_source_id` | `string` | UUID родительского `ContentSource` |
| `cited_text_clean` | `string` | `cited_text` с удалёнными Base64URL-токенами и JSON-метаданными |
| `citation_number` | `int` | Порядковый номер цитаты в ответе NLM (1-based) |

---

### 3. ResolvedAskResultDTO

Создай `App\Domain\Citations\DTOs\ResolvedAskResultDTO` через `spatie/laravel-data`:

| Поле | Тип | Описание |
|---|---|---|
| `answer` | `string` | Текст ответа из `AskResultDTO` |
| `citations` | `CitationData[]` | Массив успешно резолвнутых цитат |

---

## Edge Cases

Задокументируй в коде (PHPDoc):

- **`cited_text` начинается с Base64URL** → поиск по файлу пропускается, ID берётся напрямую из первых 22 символов строки
- **`cited_text` содержит Base64URL внутри** → метаданные удаляются на шаге 0, поиск ведётся по очищенному тексту
- **`cited_text` пересекает границу двух постов** → возвращается item, где цитата **начинается** (ближайший заголовок `>` выше позиции совпадения). Пустая строка-разделитель между items снижает вероятность этого сценария
- **`cited_text` не найден в файле** → цитата пропускается (`found: false`); NLM мог слегка изменить формулировку

---

## Критерии готовности

- `CitationResolver::resolve()` принимает `AskResultDTO` и возвращает `ResolvedAskResultDTO`
- Очистка `cited_text` удаляет все вхождения паттерна `Base64URL + JSON` корректно
- При `cited_text`, начинающемся с Base64URL, поиск по файлу не выполняется
- Base64URL декодирование — reverse операция того, что делает `BundleRenderer::encodeItemId()`
- Unit-тесты `CitationResolver`:
  - Успешный резолвинг через полнотекстовый поиск
  - Успешный резолвинг по Base64URL в начале `cited_text` (без поиска по файлу)
  - `cited_text` не найден в файле → цитата отсутствует в результате
  - Очистка метаданных из `cited_text`
  - Edge case: `cited_text` на границе двух постов
  - Один файл бандла читается один раз при нескольких цитатах с одним `nlm_source_id`