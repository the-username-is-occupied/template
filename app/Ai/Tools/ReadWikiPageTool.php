<?php

declare(strict_types=1);

namespace App\Ai\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Storage;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class ReadWikiPageTool implements Tool
{
    public function __construct(private readonly string $userspace) {}

    public function description(): Stringable|string
    {
        return 'Reads the full content of a specific wiki page by its slug. Use after ReadIndexTool to get detailed information. Pass the exact page slug as shown in the index.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'page' => $schema->string()->required(),
        ];
    }

    public function handle(Request $request): Stringable|string
    {
        $path = "{$this->userspace}/wiki/{$request['page']}.md";

        if (! Storage::disk('wiki')->exists($path)) {
            return 'Страница не найдена';
        }

        return Storage::disk('wiki')->get($path) ?? 'Страница не найдена';
    }
}
