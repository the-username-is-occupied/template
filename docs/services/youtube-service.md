# YouTubeService

`App\Services\YouTubeService` — обёртка над Google YouTube Data API v3.

---

## `getChannelInfo(string $identifier): ?ChannelInfoData`

Получает базовые сведения о канале по ID (`UC...`) или хэндлу (`@handle`).

| Параметр | Тип | Описание |
|---|---|---|
| `$identifier` | `string` | ID канала (`UC...`) или хэндл (`@GoogleDevelopers`) |

**Возвращает:** `ChannelInfoData` или `null` (если канал не найден или ошибка API).

---

## `getVideoUrls(string $id, array $types): VideoUrlsData`

Получает URL-адреса видео по ID канала или плейлиста с фильтрацией по типу контента.

| Параметр | Тип | Описание |
|---|---|---|
| `$id` | `string` | ID канала (`UC...`) или ID плейлиста (`PL...`) |
| `$types` | `array` | Фильтр типов: `'video'`, `'shorts'`, `'streams'`. По умолчанию все три. |

**Возвращает:** `VideoUrlsData` с массивом URL.

**Особенности:**
- Для канала (`UC...`) использует скрытые системные плейлисты YouTube (`UULF`, `UUSH`, `UULV`) — высокая скорость, минимальное потребление квот.
- Для плейлиста (`PL...`) выбирает все videoId из плейлиста, затем фильтрует по длительности/типу через `videos.list` (пачки по 50).
- Если запрошены все три типа — фильтрация по длительности не выполняется (экономия квот).

---

## `resolveChannelUrls(array $urls): DataCollection`

Принимает массив URL-адресов видео (разных форматов), возвращает маппинг URL → хэндл канала.

| Параметр | Тип | Описание |
|---|---|---|
| `$urls` | `array` | Ссылки на видео в форматах: `/watch?v=`, `/shorts/`, `/live/`, `youtu.be/` |

**Возвращает:** `DataCollection` из `ChannelUrlMappingData` — по одному объекту на каждый входной URL (порядок сохраняется).

**Алгоритм:**
1. Извлечение videoId из URL (регулярные выражения).
2. Пакетный запрос `videos.list(snippet)` — получение channelId (до 50 videoId за запрос).
3. Пакетный запрос `channels.list(snippet)` — получение @handle по channelId.
4. Склейка: URL → videoId → channelId → @handle.

---

## DTO

### ChannelInfoData

`App\Domain\YouTube\DTOs\ChannelInfoData`

| Поле | Тип | Описание |
|---|---|---|
| `id` | `string` | ID канала |
| `title` | `string` | Название |
| `description` | `?string` | Описание |
| `handle` | `?string` | Хэндл (например, `@GoogleDevelopers`) |
| `avatar_url` | `?string` | URL аватарки (high, fallback default) |
| `published_at` | `?string` | Дата создания канала |
| `subscribers_count` | `int` | Количество подписчиков |
| `view_count` | `int` | Общее количество просмотров |
| `video_count` | `int` | Количество видео |

### VideoUrlsData

`App\Domain\YouTube\DTOs\VideoUrlsData`

| Поле | Тип | Описание |
|---|---|---|
| `urls` | `list<string>` | Массив URL-адресов видео |

### ChannelUrlMappingData

`App\Domain\YouTube\DTOs\ChannelUrlMappingData`

| Поле | Тип | Описание |
|---|---|---|
| `url` | `string` | Исходный URL видео |
| `handle` | `?string` | Хэндл канала (`@handle`) или `null`, если не удалось определить |