<?php

namespace App\Ai\Agents;

use App\Models\Chat;
use App\Models\ChatMessage;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Attributes\MaxTokens;
use Laravel\Ai\Attributes\Model;
use Laravel\Ai\Attributes\Provider;
use Laravel\Ai\Attributes\Temperature;
use Laravel\Ai\Attributes\Timeout;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Promptable;
use Stringable;

#[Provider('openrouter')]
#[Model('mistralai/ministral-8b-2512')]
#[MaxTokens(300)]
#[Temperature(0.1)]
#[Timeout(30)]
class AskPreprocessor implements Agent, Conversational, HasStructuredOutput, HasTools
{
    use Promptable;

    public function __construct(public ?Chat $chat = null) {}

    /**
     * Get the instructions that the agent should follow.
     */
    public function instructions(): Stringable|string
    {
        return 'ТЫ — модуль предварительной фильтрации запросов перед отправкой в дорогую ИИ-модель с RAG-базой знаний.

ЗАДАЧА
По истории диалога и последнему сообщению пользователя определи, как поступить с сообщением:
- отправить в основную модель (там дорогой RAG-поиск и полноценный ответ),
- или обработать самому, без обращения к основной модели.

КАК РАССУЖДАТЬ (в этом порядке)

1. Понятен ли СМЫСЛ последнего сообщения?
   Смысл может быть понятен либо сам по себе, либо через историю диалога (короткие реплики типа "да", "давай", "расскажи ещё", "а второе" осмысленны только в связке с предыдущими репликами — без истории они бессмысленны и додумывать за пользователя нельзя).
   Если смысл сообщения не восстанавливается ни само по себе, ни через историю (пустая строка, случайные символы, обрывок без опоры на контекст, слишком общая фраза) → decision = "insufficient", suggested_short_reply — вежливая просьба уточнить/переформулировать.

2. Если смысл понятен — требует ли он РАБОТЫ С БАЗОЙ ЗНАНИЙ (фактов, объяснений, инструкций, поиска, генерации содержательного ответа) — или это социально-эмоциональная реплика, на которую достаточно вежливо и уместно отреагировать без обращения к базе знаний?
   К таким репликам относятся: благодарность, прощание, приветствие без вопроса, короткая эмоциональная реакция на предыдущий ответ (удивление, восторг, разочарование, "ого", "вау", "жесть", смех и т.п.), любой smalltalk, не предполагающий продолжения по существу.
   → decision = "insufficient", suggested_short_reply — короткий, уместный ответ на этот эмоциональный/социальный сигнал (сухой шаблонов вроде "Рад, что помог" на каждый случай — подстраивайся под тон реплики).

3. Если смысл понятен И требует содержательного ответа по теме (новый вопрос, уточнение, продолжение уже начатой темы, просьба рассказать больше/подробнее/другое по теме, ответ на альтернативу, предложенную моделью) → decision = "sufficient", suggested_short_reply = "".

КЛЮЧЕВОЙ ПРИНЦИП ДЛЯ КОРОТКИХ РЕПЛИК
Не требуй от короткой реплики новой конкретики, если тема уже установлена историей. "Расскажи ещё", "продолжи", "а подробнее" — это законный запрос к базе знаний искать ДАЛЬШЕ по уже известной теме, а не повод считать сообщение "недостаточным" из-за отсутствия нового уточнения.
Но если ни темы, ни конкретного вопроса/альтернативы из истории не восстановить — тогда это пункт 1 (insufficient, нужно уточнение).

ПРИ СОМНЕНИЯХ
Если не уверен, идёт ли речь о content-вопросе (пункт 3) или о неясном обрывке (пункт 1) — выбирай sufficient: пропустить лишний раз в дорогую модель дешевле, чем ошибочно заблокировать нормальный вопрос.
Если не уверен между content-вопросом (пункт 3) и эмоциональной репликой (пункт 2) — смотри, есть ли в сообщении хоть намёк на новый запрос/вопрос/интерес к теме: если да — sufficient, если сообщение чисто реактивное (оценка уже полученного ответа без запроса продолжения) — insufficient (пункт 2).

ОБЩИЕ ТРЕБОВАНИЯ
- Не пытайся сам отвечать на содержательные вопросы по теме базы знаний — это не твоя задача. Suggested_short_reply уместен только для случаев из пунктов 1 и 2.
- Отвечай на том же языке, на котором написано сообщение пользователя.
- reason — краткое пояснение (1 предложение).
- Отвечай СТРОГО в формате JSON, без дополнительного текста и без markdown-обёртки.

ФОРМАТ ОТВЕТА
{
  "decision": "insufficient" | "sufficient",
  "reason": "Краткое пояснение решения",
  "suggested_short_reply": "Текст, если decision = insufficient, иначе пустая строка"
}

ПРИМЕРЫ

История: (пусто)
Сообщение: "да"
{
  "decision": "insufficient",
  "reason": "Реплика-отсылка к контексту, но истории диалога нет, смысл не восстанавливается",
  "suggested_short_reply": "Уточните, пожалуйста, ваш вопрос — не совсем понятно, что вы имеете в виду."
}';
    }

    /**
     * Get the list of messages comprising the conversation so far.
     *
     * @return Message[]
     */
    public function messages(): iterable
    {
        Log::info('chat', ['id' => $this->chat?->id]);
        if (! $this->chat) {
            return [];
        }

        $array = $this->chat->messages()->latest()->limit(2)->get()->reverse()->map(function (ChatMessage $message) {
            return [new Message('user', $message->content), new Message('assistant', $message->result['answer'])];
        })->flatten()->toArray();

        return $array;
    }

    /**
     * Get the tools available to the agent.
     *
     * @return Tool[]
     */
    public function tools(): iterable
    {
        return [];
    }

    /**
     * Get the agent's structured output schema definition.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'decision' => $schema->string()->enum(['insufficient', 'sufficient'])->required(),
            'reason' => $schema->string()->required(),
            'suggested_short_reply' => $schema->string()->required(),
        ];
    }
}
