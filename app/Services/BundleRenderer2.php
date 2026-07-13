<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\ContentSource;
use App\Models\OriginalItem;
use Illuminate\Support\Collection;

class BundleRenderer2
{

    public function test(){
        $items = ContentSource::find('019f2836-9819-702f-bf23-14ce58192531')->originalItems()->limit(9)->get();

        file_put_contents('test_bundle.txt', $this->render($items));
    }
    /**
     * Render a collection of OriginalItem into a Markdown string.
     */
    public function render(Collection $items): string
    {
        $items = OriginalItem::query()
            ->with('contentSource')
            ->whereIn('id', $items->pluck('id'))
            ->get();

        $parts = $items->map(function (OriginalItem $item): string {

            return collect([
                sprintf('#%s', $item->id),
                'Метаданные:',

                sprintf('title: %s', strip_tags($item->getTitle())),
                sprintf('published_date: %s', $item->published_at?->toIso8601String() ?? ''),
                sprintf('indexed_date: %s', $item->created_at?->toIso8601String() ?? ''),
                ...$this->metaFormat($item->getMetaArray()->toArray()),
                '',
                addslashes(strip_tags($item->full_text ?? '')),
            ])->join("\n");
        });

        return $parts->implode("\n\n");
    }

    protected function metaFormat(array $array)
    {
        return collect($array)->map(function ($value, $key) {
            return sprintf('%s: %s', $key, $value);
        });
    }
}
