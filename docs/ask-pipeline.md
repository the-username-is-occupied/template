# Ask Pipeline

## Overview

Пользователь задаёт вопрос → Eolithic обогащает его историей диалога → роутит на NotebookLM через свободный технический аккаунт → резолвит цитаты к оригинальным источникам → возвращает пользователю.

---

## Conversation Strategy: Variant B

Каждый ask создаёт **новую** NLM-беседу. История предыдущих сообщений не учитывается в вопросах, но хранится в нашем сервисе.

**Почему не sticky sessions (Variant A):** `conversation_id` в NotebookLM привязан к аккаунту, который его создал. Поскольку ask-запросы распределяются по нескольким аккаунтам, нет гарантии, что следующий запрос попадёт на тот же аккаунт. Sticky требовал бы блокировки аккаунта на весь диалог — ломает load distribution.

---

## Ask Flow (End-to-End)

```
1. POST /api/ask {chat_session_id, question}
       ↓
4. Выбрать аккаунт: MIN(chats_today) среди аккаунтов из notebook_accounts
       ↓
5. Redis INCR ask_count:{account_id}:{date}
       ↓
6. FastAPI: POST /ask {account_id, notebook_id, question}
       ↓
7. NotebookLM: client.chat.ask(notebook_id, question)
       ↓  AskResult {answer, references[]}
8. Citation resolution для каждого reference:
       ↓  source-pipeline.md → citation resolution algorithm
       → {url, text, published_at}
       ↓
9. Сохранить в chat_messages (role=user + role=assistant)
       ↓
10. Вернуть {answer, resolved_references}
```

---

## Success Definition

**Ask считается успешным = ответ возвращён**, независимо от качества содержания. Пользователь платит за запрос, не за правильность ответа NLM.

Используется для:
- Request log (поле `success`)
- Billing
- Статистики аккаунтов (`chats_today`)

---

## Error Handling

| Ситуация | Поведение |
|---|---|
| Все аккаунты достигли дневного лимита | 503, показать пользователю "лимит на сегодня исчерпан" |
| Citation resolution не нашла источник | Вернуть ответ без цитат (degraded, не failed) |

---