<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\OriginalItem;
use Illuminate\Support\Collection;

class BundleRenderer2
{
     /**
     * Render a collection of OriginalItem into a Markdown string.
     */
    public function render(Collection $items): string
    {
        $items->loadMissing(['contentSource']);

        $parts = $items->map(function (OriginalItem $item): string {

            return collect([
                sprintf('# %s', strip_tags($item->getTitle())),
                '**Метаданные:**',
                sprintf('* **published_date:** %s', $item->published_at?->toIso8601String() ?? ''),
                ...$this->metaFormat($item->getMetaArray()->toArray()),
                sprintf('* **internal_id:**  %s', $item->id),
                addslashes(strip_tags("\n".$item->full_text ?? '')),
                "\n---",
            ])->join("\n");
        });

        return $parts->implode("\n\n");
    }

    protected function metaFormat(array $array)
    {
        return collect($array)->map(function ($value, $key) {
            return sprintf('* **%s:** %s', $key, $value);
        });
    }
}
