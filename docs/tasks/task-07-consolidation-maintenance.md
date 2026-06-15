# Task 07 — Bundle Consolidation, Maintenance Jobs & Auto-Update

## Контекст

Реализуем фоновые механизмы: атомарное слияние бандлов (frozen_quarter → frozen_half → frozen_full),
обслуживание пула тех. ноутбуков, очистка сирот и автообновление источников.

Изучи перед началом: `docs/bundle-architecture.md` (раздел "ConsolidateBundlesJob"), `docs/account-pool.md` (раздел "Фоновые джобы"), `docs/source-pipeline.md` (раздел "Восстановление после сбоя TG-парсинга").

Убедись, что `Task 01`–`Task 06` выполнены.

---

## Что нужно сделать

### 1. ConsolidateBundlesJob

Создай `App\Jobs\ConsolidateBundlesJob`.

Принимает `notebookId` и `level` (`'quarter_to_half'` или `'half_to_full'`).

**Принцип атомарного свопа** (строго соблюдать порядок):
1. Найти два бандла нужного уровня для ноутбука (`frozen_quarter` × 2, или `frozen_half` × 2)
2. Если не нашли два — выйти
3. Установить `is_consolidating = true` на оба исходных бандла
4. Прочитать оба MD-файла с диска, объединить через разделитель `\n\n---\n\n`
5. Создать запись нового бандла в БД со статусом `uploading`
6. **Загрузить новый бандл в NLM** (`NlmClient::uploadSource()`) — в этот момент оба старых бандла ещё существуют в NLM, чат пользователя не прерывается
7. Обновить запись нового бандла: `nlm_source_id`, `status = uploaded`
8. Перенести записи `bundle_items` со старых бандлов на новый
9. **Только теперь** удалить старые бандлы из NLM (`NlmClient::deleteSource()`)
10. Удалить старые MD-файлы с диска
11. Удалить старые записи из `md_bundles`

Сохранить `is_consolidating = false` на новом бандле в конце.

Если на шаге 9 удаление упало — залогировать, но не падать (старые бандлы будут найдены и дочищены maintenance job-ом).

Уровень маппинга:
- `quarter_to_half`: `frozen_quarter` × 2 → `frozen_half`
- `half_to_full`: `frozen_half` × 2 → `frozen_full`

### 2. Триггеры ConsolidateBundlesJob

`ConsolidateBundlesJob` диспатчится из двух мест:
1. В `BundleBuilder::flushDeltaToQuarter()` — когда появляется второй `frozen_quarter`
2. По крону (резервный триггер): ежедневно в 03:00 проверять все ноутбуки на наличие ≥ 2 бандлов уровней `frozen_quarter` или `frozen_half`

Добавь в `routes/console.php`:
```php
Schedule::job(new ScanForConsolidationJob)->dailyAt('03:00');
```

Создай `ScanForConsolidationJob` — проходит по всем ноутбукам и диспатчит `ConsolidateBundlesJob` там, где это нужно.

### 3. MaintainTechNotebooksPoolJob

Создай `App\Jobs\MaintainTechNotebooksPoolJob`.

Логика:
1. Для каждого типа (`source_extractor`, `summary_aggregator`, `global_search`) подсчитать количество активных ноутбуков (статус не `degraded`)
2. Целевой пул: 5 `source_extractor`, 1 `summary_aggregator`, 1 `global_search`
3. Если меньше целевого → создать недостающие через `AccountService::createTechNotebook()`
4. Если есть `degraded` ноутбуки → попробовать пересоздать через NLM, если не получается — пометить как `degraded` и создать замену

Добавь в scheduler: каждые 5 минут.

> Если `AccountService::createTechNotebook()` уже существует — переиспользуй. Если нет — реализуй: выбрать аккаунт с наименьшим `notebooks_count`, создать ноутбук через NLM, сохранить в `tech_notebooks`.

### 4. CleanupOrphanedSourcesJob

Создай `App\Jobs\CleanupOrphanedSourcesJob`.

Находит `content_sources`, которые:
- Созданы более 7 дней назад
- Не имеют связанного `source_draft`
- Не добавлены ни в один ноутбук (нет записей в `notebook_content_sources`)

Удаляет их вместе с их `original_items`.

Добавь в scheduler: раз в сутки.

### 5. Auto-Update (Scheduled Polling)

Реализуй механизм автообновления для источников с `auto_update = true`.

Создай `App\Jobs\AutoUpdateSourceJob`:
- Принимает `content_source_id`
- Для `telegram_channel`: вызывает `TgScraperClient::scrape()` с параметром `from_id = last_fetched_id`
- Для `youtube_channel`: вызывает `YouTubeService::getVideoUrls()`, сравнивает с уже существующими `original_items` по URL, диспатчит `ProcessSourceJob` для новых видео

Создай `App\Jobs\ScheduleAutoUpdatesJob`:
- Находит все `content_sources` с `auto_update = true` и `extraction_status = extracted`
- Для каждого диспатчит `AutoUpdateSourceJob` (с throttling — не чаще раза в час на источник)

Добавь в scheduler: каждые 30 минут.

### 6. AskService: обработка состояния консолидации

Создай или дополни `App\Services\AskService` с проверкой флага `is_consolidating`:

Перед отправкой ask-запроса в NLM проверить, есть ли у ноутбука бандлы с `is_consolidating = true`. Если да — не отправлять ask, а вернуть через SSE статус `consolidating` с дружелюбным сообщением пользователю.

Логика описана в `docs/bundle-architecture.md` раздел "Обработка ошибок во время дефрагментации".

---

## Scheduler: итоговый список задач

После этой задачи в `routes/console.php` должны быть зарегистрированы:

| Job | Расписание |
|---|---|
| `MaintainTechNotebooksPoolJob` | каждые 5 минут |
| `CleanupStaleTechNotebooksJob` (из Task 05) | каждые 15 минут |
| `ScheduleAutoUpdatesJob` | каждые 30 минут |
| Retry stale uploads (из Task 04) | каждые 30 минут |
| `ScanForConsolidationJob` | ежедневно в 03:00 |
| `CleanupOrphanedSourcesJob` | ежедневно в 04:00 |

---

## Критерии готовности

- `ConsolidateBundlesJob`: два `frozen_quarter` → один `frozen_half` в NLM, старые удалены из NLM и БД
- Атомарный своп соблюдён: новый бандл загружен в NLM до удаления старых
- `MaintainTechNotebooksPoolJob`: при нехватке `source_extractor` ноутбуков — создаёт недостающие
- `CleanupOrphanedSourcesJob`: не удаляет источники, добавленные хотя бы в один ноутбук
- `AutoUpdateSourceJob` для TG использует `last_fetched_id`
- `AskService` при `is_consolidating = true` возвращает SSE `consolidating`, не вызывает NLM
- Все NlmClient вызовы мокированы в тестах
- Feature-тест `ConsolidateBundlesJob`: проверить порядок операций (upload → delete)
