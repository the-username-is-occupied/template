# Eolithic — Product

## Vision

Eolithic — обёртка над Google NotebookLM для создания AI knowledge bases поверх Telegram, YouTube и других контент-источников. Использует notebooklm-py для автоматизированной работы с NotebookLM.

> NotebookLM с автоматическим ingest, живыми обновлениями, публичным доступом и монетизацией.

---

## Problem

**Для авторов и экспертов:**
- Ценный контент buried in Telegram/YouTube
- Архив плохо ищется, быстро теряется в ленте
- Аудитория задаёт одни и те же вопросы снова и снова
- Новые подписчики не могут быстро «распробовать» экспертизу

**Для пользователей:**
- Информационный шум, плохой поиск в Telegram
- Длинные видео без навигации
- Галлюцинации обычных LLM, отсутствие source grounding

---

## Solution

Eolithic создаёт поверх контента автора:
- Knowledge base с автоматическим ingest
- Grounded Q&A с цитатами на оригинальные источники
- Прямые ссылки на Telegram-посты и таймкоды YouTube

**Онбординг автора:** подключить канал → дождаться индексации → поделиться ссылкой. Цель: < 15 минут.

---

## Differentiators vs NotebookLM

| NotebookLM | Eolithic |
|---|---|
| Ручная загрузка файлов | Автоматический ingest целых каналов |
| Статичная база | Живые обновления по расписанию |
| Personal workspace | Публичные knowledge bases |
| Нет creator economy | Монетизация и sharing |
| Нет marketplace | Каталог knowledge bases |
| Ограниченная доступность | Доступен в регионах без NotebookLM |

---

## MVP Scope

- Telegram channel ingest
- YouTube channel / playlist ingest
- Автообновления (scheduled polling)
- Grounded Q&A с резолвингом источников
- Публичная shareable страница knowledge base

---

## Target Segments

Наиболее перспективные ниши: DevOps, AI/ML, программирование, финансы, крипта, маркетинг, research-heavy domains.

Общие признаки: высокая information density, плохой discoverability, длинный архив контента, высокая ценность точных ответов.

---

## Monetization

- Eolithic несёт стоимость технических Google-аккаунтов из выручки
- Автор может включить платный доступ к своей knowledge base
- Долгосрочно: marketplace living knowledge bases

---

## Key Risks

| Риск | Митигация |
|---|---|
| Unofficial API breakage (DBSC rollout) | Следить за notebooklm-py community, готовить fallback |
| Google банит технические аккаунты | Residential proxies, warmup, geographic matching |
| AI-wrapper commoditization | Moat вокруг automated ingest + structured memory |
| Низкий willingness to pay | Freemium + subscription |

---

## Validation Metrics

Главный вопрос раннего этапа: **люди возвращаются использовать knowledge base повторно?**

- Заменяет ли продукт поиск по Telegram?
- Есть ли recurring questions?
- Retention важнее монетизации на старте
