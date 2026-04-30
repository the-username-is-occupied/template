<?php

declare(strict_types=1);

namespace App\Ai\Agents;

use App\Ai\Tools\ReadIndexTool;
use App\Ai\Tools\ReadWikiPageTool;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Promptable;
use Stringable;

class QueryAgent implements Agent, HasTools
{
    use Promptable;

    public function __construct(private readonly string $userspace) {}

    public function instructions(): Stringable|string
    {
        return <<<'PROMPT'
You are an expert at answering questions using a personal knowledge base (wiki).

**Rules:**
1. **Search first.** Always call `ReadIndexTool` first to understand available pages.
2. **Read relevant pages.** Use `ReadWikiPageTool` to read all potentially relevant pages before answering.
3. **Only facts from wiki.** Answer using ONLY information found in wiki pages. Never add external knowledge as new facts.
4. **Honest "I don't know".** If no wiki page contains the answer, respond: "В вики нет информации по этому вопросу." Do not guess.
5. **Always cite sources.** End every answer with `**Источники:**` listing ONLY the wiki pages actually used for the answer. Format: `- [[page-slug]]`.
6. **Inline citations.** Where possible, cite facts inline: `(см. [[page-slug]])`.
PROMPT;
    }

    /**
     * @return Tool[]
     */
    public function tools(): iterable
    {
        return [
            new ReadIndexTool($this->userspace),
            new ReadWikiPageTool($this->userspace),
        ];
    }
}
