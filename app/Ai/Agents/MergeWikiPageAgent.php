<?php

declare(strict_types=1);

namespace App\Ai\Agents;

use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Promptable;
use Stringable;

class MergeWikiPageAgent implements Agent
{
    use Promptable;

    public function instructions(): Stringable|string
    {
        return <<<'PROMPT'
You are a wiki editor. You will receive two versions of the same wiki page — the existing version and a new version with additional information.

Your task:
1. Merge them into a single, coherent wiki page.
2. Preserve ALL unique information from BOTH versions — do not drop any facts, citations, or context.
3. Remove exact duplicates.
4. Keep the structure: ## Определение / Что это, ## Ключевая информация, ## Контекст и значение, ## Связи, ## Цитаты и утверждения, ## Связанные заметки.
5. In ## Связанные заметки, merge the link lists and keep relationship descriptions. Format: `- [[slug]] — relationship description`.
6. Output ONLY the merged markdown body (no frontmatter — it will be added separately).
7. Use the language of the existing page.
PROMPT;
    }
}
