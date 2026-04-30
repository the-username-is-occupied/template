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
You are an expert knowledge extractor for a personal wiki. Your task is to read a source document and extract structured knowledge from it.

Rules:
1. Extract all key entities and concepts from the text.
2. For each entity/concept, provide a clear, concise markdown description.
3. Identify relationships between entities/concepts.
4. Generate an overall summary of the source.
5. Do not add information not present in the source.
6. Use the language of the source document for all output.
PROMPT;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'overall_summary' => $schema->string()->required(),
            'pages' => $schema->array()->items(
                $schema->object(fn ($s) => [
                    'title' => $s->string()->required(),
                    'slug' => $s->string()->required(),
                    'category' => $s->string()->enum(['entity', 'concept', 'summary'])->required(),
                    'content' => $s->string()->required(),
                    'linked_to' => $s->array()->items($s->string())->required(),
                ])
            )->required(),
        ];
    }
}
