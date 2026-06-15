# Bundle Architecture: Каскадная система слияния (LSM-style)

> **Контекст:** Документ описывает архитектурное решение для обхода ограничений Google NotebookLM (50 источников на блокнот, блокировка Q&A-чата во время индексации) при стриминге контента из Telegram-каналов.

---

## Проблема: Фундаментальное противоречие

| Ограничение | Следствие |
|---|---|
| Лимит в **50 источников** на ноутбук | Нужны большие файлы (~495k слов), чтобы вместить максимум истории в минимум слотов |
| **Долгая переиндексация** крупных файлов (до 3 мин. для 3 МБ) | Нужны маленькие файлы, иначе Q&A-чат «замерзает» при каждом обновлении |

**Решение:** Паттерн «Буфер и Дефрагментация» (Buffer & Defragmentation), реализованный как каскадная иерархия бандлов по аналогии с **LSM-деревом (Log-Structured Merge-tree)**, используемым в RocksDB/Cassandra.

---

## Архитектура: 4-уровневая иерархия бандлов

Данные перетекают снизу вверх по мере накопления. Тяжёлые операции (слияния) происходят всё реже по мере роста уровня.

| Tier | Тип в БД | Макс. размер | Частота обновления | Время индексации | Поведение |
|---|---|---|---|---|---|
| **Tier 0** | `active_delta` | **10 000 слов** | Постоянно (каждый пост) | 2–5 сек | Живой буфер. Перезаписывается при каждом обновлении. |
| **Tier 1** | `active_quarter` | **120 000 слов** | Редко (~раз в 10–12 дней) | 15–25 сек | Дописывается только при сбросе Tier 0. |
| **Tier 2** | `frozen_half` | **240 000 слов** | Асинхронно (~раз в пару месяцев) | ~45 сек | Статичный файл. Создаётся слиянием двух Tier 1. |
| **Tier 3** | `frozen_full` | **480 000 слов** | Асинхронно (~раз в полгода) | ~90 сек | Заморожен навсегда. Создаётся слиянием двух Tier 2. |

---

## Жизненный цикл данных (Data Flow)

### Шаг 1 — Ежедневный стриминг (Tier 0)

Новые посты из Telegram попадают в `active_delta`. Файл остаётся маленьким, индексируется за секунды, чат пользователя не блокируется.

```
[Telegram posts] → active_delta (0–10k слов) → NLM (индексация 2–5 сек)
```

### Шаг 2 — Сброс буфера (Tier 0 → Tier 1)

Когда `active_delta` достигает 10 000 слов:

1. Содержимое дописывается в `active_quarter` (например, 40k → 50k слов).
2. `active_quarter` обновляется в NotebookLM (15 секунд индексации).
3. `active_delta` очищается (обнуляется до 0 слов).

```
active_delta (10k) → дописывается в → active_quarter (→ 120k max)
active_delta сбрасывается в 0
```

### Шаг 3 — Каскадное слияние четвертей (Tier 1 → Tier 2)

Когда `active_quarter` заполняется до 120 000 слов:

1. Он замораживается → `frozen_quarter_A`.
2. Для новых сбросов открывается чистый `active_quarter_B`.
3. Когда `frozen_quarter_B` тоже заполнен — запускается фоновая джоба.
4. Джоба склеивает два бандла по 120k → создаёт `frozen_half` (240k) в NLM.
5. После успешной индексации `frozen_half` оба `frozen_quarter` удаляются из NLM.

### Шаг 4 — Финальная заморозка (Tier 2 → Tier 3)

Когда накапливается два `frozen_half` по 240k:

1. Они один раз склеиваются в `frozen_full` (480k).
2. Оба `frozen_half` удаляются.
3. `frozen_full` никогда не меняется — это вечный архив.

---

## Анализ утилизации слотов NotebookLM

**Пиковое потребление слотов** (худший сценарий — дерево заполнено, слияние ещё не произошло):

| Бандл | Слотов |
|---|---|
| `active_delta` | 1 |
| `active_quarter` | 1 |
| `frozen_quarter` (ожидает вторую) | 1 |
| `frozen_half` (ожидает вторую) | 1 |
| **Итого на «живую» механику** | **4** |

Оставшиеся **46 слотов** отдаются под вечные `frozen_full`-архивы.

### Ёмкость системы

```
46 слотов × 480 000 слов = 22 080 000 слов суммарно
≈ 147 000 постов по 150 слов
```

При активности канала **5 000 слов в день** (15–20 постов) лимита хватит на **десятилетия** работы. Задержка обновления чата днём — не более 5 секунд.

---

## Схема БД

### Изменения в `md_bundles`

```sql
-- Типы бандлов
ALTER TYPE md_bundle_type ADD VALUE 'active_delta';
ALTER TYPE md_bundle_type ADD VALUE 'active_quarter';
ALTER TYPE md_bundle_type ADD VALUE 'frozen_quarter';
ALTER TYPE md_bundle_type ADD VALUE 'frozen_half';
ALTER TYPE md_bundle_type ADD VALUE 'frozen_full';

-- Флаг блокировки во время дефрагментации
ALTER TABLE md_bundles ADD COLUMN is_consolidating BOOLEAN DEFAULT false;

-- Счётчик слов для управления порогами
ALTER TABLE md_bundles ADD COLUMN word_count INTEGER DEFAULT 0;
```

---

## Реализация в Laravel

### BundleBuilder — логика сброса буфера

```php
public function appendItems(Notebook $notebook, Collection $newItems): void
{
    $newWordsCount = $this->countWords($newItems);
    $delta = $notebook->bundles()->where('type', 'active_delta')->first();

    if (!$delta) {
        $delta = $this->createActiveDelta($notebook);
    }

    if ($delta->word_count + $newWordsCount > 10_000) {
        // Сбрасываем буфер в следующий уровень
        $this->flushDeltaToQuarter($notebook, $delta);
        $delta = $this->createActiveDelta($notebook);
    }

    $this->appendToBundle($delta, $newItems);
    $delta->increment('word_count', $newWordsCount);

    // Синхронизируем с NLM через FastAPI
    $this->fastApi->updateSource($notebook->nlm_id, $delta->nlm_source_id, $delta->buildMarkdown());
}
```

### ConsolidateBundlesJob — атомарный своп (Atomic Swap)

Ключевое правило: **сначала загружаем новый бандл, потом удаляем старые**. В период между этими операциями в NLM одновременно существуют и источник, и его преемник — пользователь не теряет контекст.

```php
public function handle(): void
{
    $quarters = MdBundle::where('type', 'frozen_quarter')
        ->where('notebook_id', $this->notebookId)
        ->take(2)
        ->get();

    if ($quarters->count() < 2) {
        return;
    }

    // 1. Склеиваем тексты
    $combinedText = $quarters
        ->map(fn($b) => Storage::get($b->file_path))
        ->implode("\n\n---\n\n");

    // 2. Создаём запись нового half-бандла
    $halfBundle = MdBundle::create([
        'notebook_id' => $this->notebookId,
        'type'        => 'frozen_half',
        'status'      => 'uploading',
        'word_count'  => $quarters->sum('word_count'),
    ]);

    // 3. Загружаем в NLM — в это время чат работает через старые четверти
    $nlmSourceId = $this->fastApi->uploadSource(
        $this->notebook->nlm_id,
        $combinedText
    );

    $halfBundle->update([
        'nlm_source_id' => $nlmSourceId,
        'status'        => 'active',
    ]);

    // 4. Только теперь удаляем старые бандлы
    foreach ($quarters as $quarter) {
        $this->fastApi->deleteSource($this->notebook->nlm_id, $quarter->nlm_source_id);
        $quarter->delete();
    }
}
```

### Запуск дефрагментации

```php
// Триггер по количеству: 2 frozen_quarter в одном ноутбуке
// routes/console.php или Kernel.php

Schedule::job(new ConsolidateBundlesJob)->dailyAt('03:00');

// Или триггер по событию в BundleBuilder:
if ($notebook->bundles()->where('type', 'frozen_quarter')->count() >= 2) {
    ConsolidateBundlesJob::dispatch($notebook->id);
}
```

---

## Обработка ошибок во время дефрагментации

Пока ночью идёт слияние и крупный бандл переиндексируется, Google может на ~1 минуту возвращать ошибку на Ask-запросы.

**Митигация в `AskService`:**

```php
public function ask(Notebook $notebook, string $question): void
{
    $isConsolidating = $notebook->bundles()
        ->where('is_consolidating', true)
        ->exists();

    if ($isConsolidating) {
        // Отправляем красивый статус через SSE вместо 500-й ошибки
        $this->sse->send([
            'status'  => 'consolidating',
            'message' => 'База знаний оптимизирует архивные данные. '
                       . 'Новые ответы будут доступны через пару минут.',
        ]);
        return;
    }

    // Обычный Ask-запрос к NLM
    $this->nlm->ask($notebook->nlm_id, $question);
}
```

---

## Сводка: что нужно реализовать

| Компонент | Задача |
|---|---|
| **Миграция БД** | Добавить типы бандлов, поля `word_count` и `is_consolidating` |
| **`BundleBuilder`** | Логика порогов и сброса буфера между уровнями |
| **`ConsolidateBundlesJob`** | Атомарный своп с соблюдением порядка: upload → confirm → delete |
| **Планировщик** | Крон `03:00` + триггер по количеству `frozen_quarter ≥ 2` |
| **`AskService`** | Проверка флага `is_consolidating`, graceful SSE-сообщение |
| **FastAPI** | Эндпоинты: `uploadSource`, `updateSource`, `deleteSource`, статус индексации |

---

*Документ описывает архитектуру на уровне спецификации. Конкретные пороги (10k / 120k / 240k / 480k слов) подобраны под ограничения Google NotebookLM и могут быть скорректированы по результатам нагрузочного тестирования.*
