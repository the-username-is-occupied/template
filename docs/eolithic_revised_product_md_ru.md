# Eolithic.io — Product Overview (Revised)

## 1. Видение

**Домен:** https://eolithic.io  
**Девиз:** *Eolithic. Carved truth.*

Eolithic — это платформа для создания **живых AI knowledge bases** поверх Telegram, YouTube и других контент-источников.

Проще говоря:

> NotebookLM для creator- и expert-контента — но с автоматическим ingest, живыми обновлениями, публичным доступом и монетизацией.

Eolithic превращает поток контента в:
- searchable knowledge layer,
- grounded AI assistant,
- continuously evolving memory system.

Пользователь получает быстрые ответы с цитатами на оригинальные источники.
Автор контента — новый слой монетизации и discoverability поверх уже созданного архива.

---

# 2. Проблема

## Для авторов и экспертов

Большая часть ценного контента сегодня:
- buried in Telegram,
- buried in YouTube,
- плохо ищется,
- быстро теряется в ленте.

Даже крупные creators и эксперты сталкиваются с тем, что:
- аудитория задает одни и те же вопросы,
- архив контента почти не монетизируется,
- старые инсайты исчезают из информационного потока,
- новые подписчики не способны быстро «распробовать» экспертизу.

При этом у многих creators уже есть:
- сотни постов,
- десятки часов видео,
- годы накопленных знаний.

Но этот архив остается «мертвым». Его невозможно нормально использовать как knowledge system.

---

## Для пользователей

Пользователь сталкивается с:
- информационным шумом,
- плохим поиском в Telegram,
- длинными видео без навигации,
- галлюцинациями обычных LLM,
- отсутствием source grounding.

Чтобы найти один конкретный ответ, человеку приходится:
- читать десятки постов,
- смотреть длинные видео,
- вручную искать контекст.

Это создает огромный friction.

---

# 3. Решение

Eolithic создает поверх контента автора:

- живую AI knowledge base,
- searchable memory layer,
- grounded Q&A interface.

Автор подключает:
- Telegram-канал,
- YouTube-канал,
- плейлисты,
- документы,
- Notion (в будущем),
- другие knowledge sources.

Система автоматически:
- индексирует контент,
- обновляет knowledge base,
- структурирует темы,
- связывает сущности,
- позволяет задавать вопросы поверх всей базы.

Ответы:
- grounded на источниках,
- содержат ссылки,
- показывают таймкоды и origin,
- минимизируют hallucinations.

---

# 4. Ключевая идея

Eolithic — это не «AI-клон личности».

Платформа не пытается:
- имитировать человека,
- копировать голос,
- заменять автора.

Eolithic строит:

> knowledge interface поверх архива контента.

Главная ценность:
- retrieval,
- synthesis,
- discoverability,
- continuity of knowledge.

---

# 5. Core Product

## Базовый UX

### Для автора

1. Подключить Telegram / YouTube
2. Дождаться индексации
3. Получить AI knowledge base
4. Поделиться ссылкой
5. Опционально включить монетизацию

Весь onboarding должен занимать менее 10–15 минут.

---

## Для пользователя

1. Открыть knowledge base
2. Задать вопрос
3. Получить grounded answer
4. Перейти к оригинальным источникам

---

# 6. Что отличает Eolithic от NotebookLM

| NotebookLM | Eolithic |
|---|---|
| Ручная загрузка файлов | Автоматический ingest целых источников |
| Статичная база | Живые обновления |
| Personal workspace | Публичные knowledge bases |
| Нет creator economy | Монетизация и sharing |
| Нет marketplace | Каталог knowledge bases |
| Ограниченная social layer | Возможность использовать чужие базы |
| Ограниченная доступность | Доступен в регионах, где NotebookLM отсутствует |

---

# 7. Архитектурный подход

## Living Knowledge Base

Главная техническая идея:

> knowledge evolves continuously.

Обычный RAG:
- загружает документы,
- ищет чанки,
- stateless retrieval.

Eolithic:
- continuously ingests new content,
- обновляет knowledge structure,
- поддерживает evolving memory layer.

---

## Data Layers

| Layer | Description |
|---|---|
| Raw Sources | Telegram posts, YouTube transcripts, files |
| Structured Memory | Summaries, entities, topic pages |
| Retrieval Layer | Routing + semantic retrieval |
| Knowledge Graph (future) | Explicit relations between concepts |

---

## LLM Wiki Approach

Eolithic использует подход persistent LLM Wiki:

- knowledge компилируется,
- структурируется,
- обновляется инкрементально,
- а не переизвлекается с нуля каждый запрос.

Это позволяет:
- снижать стоимость retrieval,
- сохранять контекст,
- видеть chronology,
- отслеживать evolution of ideas.

---

# 8. Knowledge Graph (Future Direction)

На MVP полноценный граф не обязателен.

Базовая retrieval-система может работать через:
- summaries,
- indexes,
- metadata,
- cross-links.

Но архитектура закладывается под knowledge graph.

В будущем граф позволит:

- улучшать retrieval,
- искать semantic bridges,
- находить contradictions,
- строить maps of expertise,
- показывать evolution of thought,
- делать cross-expert synthesis.

---

# 9. Монетизация

## Базовая модель

Автор может:
- сделать knowledge base публичной,
- поделиться ссылкой,
- включить платный доступ.

Варианты монетизации:

### 1. Subscription access

Наиболее перспективная модель.

Например:
- бесплатные вопросы,
- затем monthly subscription.

---

### 2. Creator membership enhancement

AI knowledge base становится частью:
- premium community,
- creator membership,
- paid subscription.

---

### 3. Pay-per-query (optional)

Подходит для:
- экспертных ниш,
- дорогих domain answers,
- high-value consultations.

Но не должен быть единственной моделью.

---

# 10. Marketplace

Eolithic развивается как:

> marketplace of living knowledge bases.

Пользователи могут:
- находить базы,
- использовать базы,
- комбинировать базы,
- использовать чужие базы как knowledge source.

---

## Composable Knowledge

Одна из долгосрочных идей:

Пользователь может объединить:
- собственные документы,
- Telegram эксперта,
- YouTube-архив,
- external docs,
- другие public bases.

И создать composable AI workspace.

---

# 11. Самые перспективные сегменты

## Особенно сильные ниши

- DevOps
- AI / ML
- программирование
- финансы
- крипта
- маркетинг
- enterprise knowledge
- research-heavy domains

Потому что:
- там высокий information density,
- плохой discoverability,
- длинный архив контента,
- высокая ценность точных ответов.

---

# 12. Главный moat

Главный moat Eolithic — не просто AI.

И не просто RAG.

А:

- continuous ingest,
- evolving memory,
- structured normalization,
- grounded retrieval,
- living knowledge infrastructure.

Самая сложная часть продукта:
- parsing,
- updates,
- deduplication,
- chronology,
- contradiction handling,
- evolving structure.

---

# 13. Стратегия MVP

## MVP должен быть максимально простым

### MVP Scope

- Telegram ingest
- YouTube ingest
- auto-updates
- grounded Q&A
- sharable public page

Без:
- сложного graph UI,
- Neo4j,
- ontology system,
- advanced marketplace mechanics,
- сложных access tiers.

---

# 14. Что валидировать первым

Главный вопрос раннего этапа:

> Люди возвращаются использовать knowledge base повторно?

Retention важнее монетизации.

Нужно проверить:
- заменяет ли продукт поиск по Telegram,
- задают ли пользователи recurring questions,
- используют ли knowledge base регулярно.

Если retention есть — monetization можно улучшать позже.

---

# 15. Риски

| Риск | Комментарий |
|---|---|
| AI-wrapper commoditization | Нужно строить moat вокруг ingest + structured memory |
| Low-quality creator flood | Важно развивать quality ranking и trust layer |
| Низкая willingness to pay | Нужны freemium и subscription модели |
| Dependency on LLM APIs | Multi-provider architecture |
| Marketplace spam | Reputation system и ranking |

---

# 16. Долгосрочное видение

Eolithic может стать:

- knowledge layer for creators,
- memory layer for organizations,
- marketplace of grounded expertise,
- infrastructure for living AI knowledge systems.

Не просто AI-chat поверх PDF.

А:

> continuously evolving, source-grounded knowledge network.

---

# 17. Заключение

Eolithic превращает Telegram, YouTube и другие контент-потоки в:
- searchable knowledge,
- grounded AI assistants,
- living memory systems.

Платформа помогает:

Авторам:
- переиспользовать архив,
- улучшать discoverability,
- монетизировать knowledge layer.

Пользователям:
- получать быстрые grounded answers,
- находить информацию мгновенно,
- работать с длинным контентом как с knowledge base.

Eolithic — это не AI-клон человека.

Это:

> living knowledge infrastructure.

*Eolithic. Carved truth.*

