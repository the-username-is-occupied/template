# Task 08 — Citation Resolution

## Контекст

Реализуем механизм резолвинга цитат: когда NLM возвращает ответ с фрагментом текста из бандла,
нужно найти конкретный `original_item` и вернуть его `source_url` и мета-данные.

Изучи перед началом: `docs/source-pipeline.md` (раздел "Citation Resolution", "Bundle File Format").


---

## Что нужно сделать

### 1. CitationResolver

Создай `App\Services\CitationResolver` с методом `resolve(string $nlmSourceId, string $citedText): ?CitationData`.

Алгоритм (точно по `docs/source-pipeline.md`):
1. Найти `MdBundle` по `nlm_source_id` (поле в БД)
2. Прочитать MD-файл бандла с диска (`Storage::get($bundle->file_path)`)
3. Найти позицию `$citedText` в тексте файла (через регулярное выражение)
4. Если не найдено — вернуть `null`
5. Сканировать назад от позиции совпадения до первого вхождения `>` в начале строки
6. Считать следующие 22 символа до первого пробела
7. Валидировать регулярным выражением `^[A-Za-z0-9_-]{22}$`
8. Декодировать Base64URL обратно в UUID (reverse операция из `BundleRenderer`)
9. Найти `OriginalItem` WHERE `id = $decodedUuid`
10. Вернуть `CitationData` с полями `url`, `title`, `published_at`, `source_type`

Если на любом шаге произошла ошибка (файл не найден, UUID невалидный, item не найден) — вернуть `null`, залогировать warning.

### 2. CitationData DTO

Создай `App\Domain\Citations\DTOs\CitationData` через `spatie/laravel-data`:

| Поле | Тип | Описание |
|---|---|---|
| `source_url` | `string` | Permalink оригинального поста/видео/страницы |
| `title` | `?string` | Заголовок item-а |
| `published_at` | `?string` | Дата публикации ISO 8601 |
| `source_type` | `string` | Тип источника (`telegram_channel`, `youtube_video`, etc.) |
| `content_source_id` | `string` | UUID родительского `ContentSource` |

### 3. API Endpoint

Создай `App\Http\Controllers\Api\CitationController` с методом `resolve`.

Маршрут: `POST /api/citations/resolve`

Request body:
```json
{
  "nlm_source_id": "abc123",
  "cited_text": "Фрагмент текста из ответа NLM"
}
```

Response `200`:
```json
{
  "found": true,
  "citation": {
    "source_url": "https://t.me/habr_com/12345",
    "title": "Заголовок поста",
    "published_at": "2024-05-12T14:30:00Z",
    "source_type": "telegram_channel",
    "content_source_id": "uuid"
  }
}
```

Response `200` (не найдено):
```json
{
  "found": false,
  "citation": null
}
```

Endpoint авторизован. Проверь, что `nlm_source_id` принадлежит бандлу ноутбука, доступного текущему пользователю (через `notebook.user_id`).

### 4. Batch Resolution

Добавь метод `resolveBatch` для эффективного резолвинга нескольких цитат из одного ответа NLM:

`POST /api/citations/resolve-batch`

Request body:
```json
{
  "citations": [
    { "nlm_source_id": "abc", "cited_text": "..." },
    { "nlm_source_id": "abc", "cited_text": "..." }
  ]
}
```

Оптимизация: файл бандла читается с диска один раз для всех цитат с одинаковым `nlm_source_id`.

### 5. Кеширование

Добавь кеш для прочитанных файлов бандлов на время одного HTTP-запроса (in-memory, через простой массив внутри `CitationResolver`). При последовательных вызовах `resolve()` с одним `nlm_source_id` файл читается только один раз.

---

## Edge Cases

Задокументируй в коде (PHPDoc):
- `cited_text` пересекает границу двух постов → возвращается item, где цитата **начинается** (ближайший заголовок выше позиции совпадения)
- Пустая строка-разделитель между items снижает вероятность этого сценария
- Если `cited_text` не найден в файле → `found: false` (NLM мог слегка изменить формулировку)

---

## Критерии готовности

- `CitationResolver::resolve()` корректно находит `OriginalItem` по фрагменту текста из MD-файла
- Base64URL декодирование — reverse операция того, что делает `BundleRenderer::encodeItemId()`
- `POST /api/citations/resolve` требует авторизации, проверяет принадлежность бандла пользователю
- `POST /api/citations/resolve-batch` читает файл бандла только один раз при нескольких цитатах из него
- Unit-тесты `CitationResolver`: успешный резолвинг, не найдено, edge case с границей двух постов
- Feature-тест: авторизованный запрос → корректный `source_url` в ответе
