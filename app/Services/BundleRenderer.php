<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\OriginalItem;
use Illuminate\Support\Collection;

class BundleRenderer
{
    /**
     * Render a collection of OriginalItem into a Markdown string.
     *
     * Format per item:
     * > {base64url_uuid} {"title":"...","date":"..."}
     * {full_text}
     *
     * Items are separated by a blank line.
     */
    public function render(Collection $items): string
    {
        $parts = $items->map(function (OriginalItem $item): string {
            $encodedId = $this->encodeItemId($item->id);
            $header = sprintf(
                '> %s {"title":"%s","date":"%s"}',
                $encodedId,
                addslashes($item->title ?? ''),
                $item->published_at?->toIso8601String() ?? '',
            );

            return $header."\n".($item->full_text ?? '');
        });

        return $parts->implode("\n\n");
    }

    /**
     * Convert a UUID (36 chars with dashes) to a 22-character Base64URL string.
     *
     * Steps:
     * 1. Remove dashes from the UUID
     * 2. Decode hex string to binary
     * 3. Encode binary to base64
     * 4. Strip padding (=)
     * 5. Replace + and / with URL-safe characters
     */
    public function encodeItemId(string $uuid): string
    {
        $hex = str_replace('-', '', $uuid);
        $binary = hex2bin($hex);

        return rtrim(strtr(base64_encode($binary), '+/', '-_'), '=');
    }
}
