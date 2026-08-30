<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\OriginalItem;
use Illuminate\Support\Collection;
use InvalidArgumentException;

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

    /**
     * Decode a 22-character Base64URL string back to a UUID.
     *
     * Reverse of encodeItemId():
     * 1. Add padding (=) to make length a multiple of 4
     * 2. Reverse character substitution: - → +, _ → /
     * 3. Base64 decode to binary (16 bytes)
     * 4. Binary to hex
     * 5. Format as UUID (8-4-4-4-12)
     *
     * @param  string  $base64url  Must match ^[A-Za-z0-9_-]{22}$
     *
     * @throws InvalidArgumentException If the input is not a valid Base64URL string
     */
    public function decodeItemId(string $base64url): string
    {
        if (preg_match('/^[A-Za-z0-9_-]{22}$/', $base64url) !== 1) {
            throw new InvalidArgumentException('Invalid Base64URL identifier: '.$base64url);
        }

        // Step 1 & 2: Add padding and reverse substitution
        $padded = str_pad(
            strtr($base64url, '-_', '+/'),
            strlen($base64url) + (4 - (strlen($base64url) % 4)) % 4,
            '=',
            STR_PAD_RIGHT
        );

        // Step 3: Base64 decode to binary
        $binary = base64_decode($padded, true);

        if ($binary === false) {
            throw new InvalidArgumentException('Failed to base64-decode: '.$base64url);
        }

        // Step 4: Binary to hex
        $hex = bin2hex($binary);

        // Step 5: Format as UUID (8-4-4-4-12)
        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12)
        );
    }
}
