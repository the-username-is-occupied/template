<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Source;
use Illuminate\Support\Collection;
use Illuminate\Support\HtmlString;

class SourceIdRenderer
{
    /**
     * @return array{html: HtmlString, source_uuids: array<int, string>, sources: Collection<int, Source>}
     */
    public function render(string $text): array
    {
        $sourceUuids = $this->extractSourceUuids($text);
        $sources = Source::query()
            ->whereIn('uuid', $sourceUuids)
            ->get()
            ->keyBy('uuid');

        $escapedText = e($text);
        $html = preg_replace_callback('/\[SOURCE_ID:([a-f0-9\-]+)\]/i', function (array $matches) use ($sources): string {
            $uuid = strtolower($matches[1]);
            $source = $sources->get($uuid);

            if (! $source instanceof Source) {
                return e($matches[0]);
            }

            return sprintf(
                '<a href="%s" class="text-blue-700 underline decoration-blue-300 underline-offset-2" title="%s">%s</a>',
                e(route('hipporag.sources.show', $source)),
                e($source->original_name),
                e($source->original_name)
            );
        }, $escapedText) ?? $escapedText;

        return [
            'html' => new HtmlString(nl2br($html, false)),
            'source_uuids' => $sourceUuids,
            'sources' => $sources->values(),
        ];
    }

    /**
     * @return array<int, string>
     */
    public function extractSourceUuids(string $text): array
    {
        preg_match_all('/\[SOURCE_ID:([a-f0-9\-]+)\]/i', $text, $matches);

        return collect($matches[1] ?? [])
            ->map(fn (string $uuid): string => strtolower($uuid))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  array<int, string>  $sourceUuids
     * @return array<int, array{uuid: string, filename: string, url: string}>
     */
    public function sourceLinks(array $sourceUuids): array
    {
        return Source::query()
            ->whereIn('uuid', array_unique($sourceUuids))
            ->get()
            ->map(fn (Source $source): array => [
                'uuid' => $source->uuid,
                'filename' => $source->original_name,
                'url' => route('hipporag.sources.show', $source),
            ])
            ->values()
            ->all();
    }
}
