# Testing Guide: Source Pipeline

## Философия

Пайплайн — асинхронная, многоступенчатая система. Тестируем на двух уровнях:

- **Unit-тесты** — изолированная проверка каждого класса. Все внешние зависимости (NLM, YouTube API, TG Scraper, Redis locks, SSE) мокаются.
- **Feature-тесты** — сквозные сценарии через HTTP (как клиент видит систему). Мокаются только внешние HTTP-вызовы и SSE-listeners.


---

## Моки и фабрики

### NotebookLMService (DTO factories)

В `database/factories` есть фабрики для DTO, которые возвращает `NotebookLMService` . Используем их во всех тестах, где нужен ответ NLM:


Мокируем сам `NotebookLMService`:

```php
$this->mock(NotebookLMService::class)
            ->shouldReceive('getNotebook')
            ->andReturn(NotebookDTOFactory::make(['title' => 'My Notebook']));

        $response = app(NotebookLMService::class)->getNotebook('', '');

        $this->assertEquals('My Notebook', $response->title);
```

### YouTubeService

```php
$yt = $this->mock(YouTubeService::class);

```


### SSE (Event::fake + мок Listeners)

**Важно:** SSE-события не отправляются напрямую из бизнес-логики. Вместо этого:

1. Бизнес-сервис диспатчит Laravel Event 
2. Listenerподхватывает и пушит в Mercure Hub

В тестах мокируем **listeners**, а не сам `SseService` или `MercurePublisher`:

---
