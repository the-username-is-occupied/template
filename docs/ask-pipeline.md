# Ask Pipeline

## Overview

Пользователь задаёт вопрос → роутит на NotebookLM через свободный технический аккаунт → созраняет в бд → резолвит цитаты к оригинальным источникам → возвращает пользователю.

---
1. Для TechAccount добавить таблицу usage для подсчета кол-во запросов с аккаунта. Подсчитываем за каждый день, инкрементом
2. Для AccountService добавить методы получения TechAccount для ask запроса. Выбираем по наименьшему кол-ву запросов за день 
3. В AskService в методе ask добавить инкремент кол-ва запросов после получения ответа от notebookLMService. Так же добавить выборку techAccount через AccountService перед отправкой. 
4. Модели chat и message
5. После получения ответа в модели chat_messages в json поле result сериализовать AskResultDTO через toArray()  
## Ask Flow (End-to-End)

```
1. AskService->ask {$notebook, question}
       ↓
4. Выбрать аккаунт: MIN(chats_today) среди аккаунтов из tech_accounts
       ↓
6. FastAPI: POST /ask {account_id, notebook_id, question}
       ↓
7. NotebookLM: client.chat.ask(notebook_id, question)
       ↓  AskResultDTO

       ↓
9. Сохранить в chat_messages (role=user + role=assistant)
       ↓
10. Вернуть {answer, resolved_references}
```

---

## Success Definition

**Ask считается успешным = ответ возвращён**, независимо от качества содержания. Пользователь платит за запрос, не за правильность ответа NLM.
 
- поле `is_success`

---

## Error Handling

| Ситуация | Поведение |
|---|---|
| Все аккаунты достигли дневного лимита | 503, показать пользователю "лимит на сегодня исчерпан" |

---