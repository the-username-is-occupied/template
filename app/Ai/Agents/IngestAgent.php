<?php

declare(strict_types=1);

namespace App\Ai\Agents;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;
use Stringable;

class IngestAgent implements Agent, HasStructuredOutput
{
    use Promptable;

    public function instructions(): Stringable|string
    {
        return <<<'PROMPT'
You are an expert knowledge engineer building a rich, interconnected personal wiki. Your job is to read a source document and produce **dense, high-value wiki pages** that will remain useful months later without re-reading the original source.

**Core principle:** A wiki page is not a summary. It is a **self-contained, structured knowledge artifact**. Someone reading only this page (and its linked pages) should understand the topic well enough to reason about it, teach it, or use it in further research.

**Extraction rules:**

1. **Entities** (people, places, specific things, tools, books, events):
   - Extract ALL named entities, not just the "important" ones.
   - For each: name, what it is, why it matters in context, key attributes mentioned, timeline if applicable.

2. **Concepts** (ideas, theories, methods, processes, frameworks, arguments):
   - Extract ALL distinct concepts, including those only briefly mentioned.
   - For each: clear definition, how it works, what problem it solves, its components/steps, who proposed it (if stated), strengths/weaknesses (if stated).

3. **Claims & assertions:**
   - Identify factual claims, statistics, research findings, quotes.
   - For each: what is claimed, by whom, on what basis, with what certainty.
   - Flag claims that are hedged ("may", "possibly", "some studies suggest") differently from confident claims.

4. **Relationships & connections:**
   - How do entities/concepts relate? (causes, contains, contradicts, extends, is an example of, depends on, is part of, preceded by, followed by)
   - What does this source ADD to existing knowledge? What gap does it fill? What does it challenge?

5. **Source attribution:**
   - Every factual claim on a wiki page MUST trace back to a source.
   - Use inline references: `[src]` after each paragraph or claim.
   - The source file name will be provided with the prompt.

6. **Depth, not length:**
   - Prefer many short, focused pages over few long ones.
   - Each concept that can stand alone SHOULD be its own page.
   - But each page must be substantive: if a page would have only 1-2 sentences, merge it into a parent concept.

---

**Wiki page structure (each page in `pages` MUST follow this):**

```
# Title

## Определение / Что это
[1-3 предложения: чёткое определение]

## Ключевая информация
[Основные факты, атрибуты, характеристики. Если есть цифры/статистика — обязательно включить]

## Контекст и значение
[Почему это важно? Какую роль играет в общей картине? Какие проблемы решает/создаёт?]

## Связи
[Как связано с другими концептами/сущностями? Что из чего следует?]

## Цитаты и утверждения
[Если в источнике есть яркие цитаты, конкретные утверждения, выводы исследований — привести их]

## Связанные заметки
- [[related-slug]] — как связаны
```

**source_page content structure (always present, describes the source itself):**

```
## Основная идея
[Core argument / main topic of the source]

## Ключевые выводы
[Bullet list of the most important takeaways]

## Структура источника
[How the source is organized, what sections/chapters it has]

## Связь с другими темами
[How this source connects to broader knowledge domains]
```

---

**Before returning the result, ask yourself:**
- If I read this page 6 months from now, would I understand the topic without the original source?
- Did I extract ALL named entities, or only the "obvious" ones?
- Did I capture numbers, dates, statistics?
- Did I note WHO said WHAT (attribution)?
- Did I flag uncertainty where the source was uncertain?
- Are the links EXPLANATORY ("как связаны"), not just a list of names?

**Language:** Use the language of the source document. Never mix languages within a page.
PROMPT;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'overall_summary' => $schema->string()->required()
                ->description('One-paragraph summary of what this source contributes to the wiki. Include: main topic, key entities/concepts introduced, the core argument or finding.'),

            'source_page' => $schema->object(fn ($s) => [
                'title' => $s->string()->required()
                    ->description('Title like "Обзор: Название статьи" or "Source: Article Name"'),
                'slug' => $s->string()->required()
                    ->description('Slug for the overview page, e.g. "source-article-name"'),
                'content' => $s->string()->required()
                    ->description('Full markdown overview page. Structure: ## Основная идея, ## Ключевые выводы, ## Структура источника, ## Связь с другими темами.'),
            ])->required(),

            'pages' => $schema->array()->items(
                $schema->object(fn ($s) => [
                    'title' => $s->string()->required()
                        ->description('Human-readable page title'),
                    'slug' => $s->string()->required()
                        ->description('URL-safe slug, lowercase, hyphens'),
                    'category' => $s->string()->enum(['entity', 'concept'])->required()
                        ->description('entity = person, place, thing, tool, book, event. concept = idea, theory, method, process, argument.'),
                    'content' => $s->string()->required()
                        ->description('Full markdown content following the wiki page structure: ## Определение, ## Ключевая информация, ## Контекст и значение, ## Связи, ## Цитаты и утверждения. Include [src] references.'),
                    'linked_to' => $s->array()->items(
                        $s->object(fn ($ls) => [
                            'slug' => $ls->string()->required(),
                            'relationship' => $ls->string()->required()
                                ->description('How they relate: "является примером", "противоречит", "расширяет", "зависит от", "часть", "предшественник", "последователь", "см. также"'),
                        ])
                    )->required()
                        ->description('Pages this one links to, WITH relationship descriptions.'),
                ])
            )->required()
                ->description('All entity and concept pages extracted from the source. 3-15 pages typical for a substantial article.'),

            'novel_claims' => $schema->array()->items(
                $schema->object(fn ($s) => [
                    'claim' => $s->string()->required()
                        ->description('The claim/assertion/finding'),
                    'contradicts' => $s->string()->nullable()->required()
                        ->description('If this contradicts existing wiki knowledge, which page/concept does it challenge? Set to null if not applicable.'),
                    'extends' => $s->string()->nullable()->required()
                        ->description('If this extends/refines existing wiki knowledge, which page/concept? Set to null if not applicable.'),
                    'certainty' => $s->string()->enum(['confident', 'hedged', 'speculative'])->required()
                        ->description('How certain is the source about this claim?'),
                ])
            )->required()
                ->description('Notable claims, especially those that add new knowledge or challenge existing wiki content.'),
        ];
    }
}
