<?php

declare(strict_types=1);

namespace App\Ai\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Storage;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class ReadIndexTool implements Tool
{
    public function __construct(private readonly string $userspace) {}

    public function description(): Stringable|string
    {
        return 'Reads the wiki index file (index.md) for the current userspace. Returns a list of all available wiki pages with their categories and descriptions. Always use this tool first when answering a question to understand what pages exist.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    public function handle(Request $request): Stringable|string
    {
        $path = "{$this->userspace}/wiki/index.md";

        if (! Storage::disk('wiki')->exists($path)) {
            return 'Индекс пуст';
        }

        return Storage::disk('wiki')->get($path) ?? 'Индекс пуст';
    }
}
